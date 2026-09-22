"""Independent timing oracles and strict clip-analysis domain boundaries."""

from copy import deepcopy

import pytest

from aiclip_worker.clip_analysis import (
    ClipAnalysisInput, ClipAnalysisResult, ClipValidationError,
    DeterministicClipCandidateAnalyzer, quantize,
)


def golden_contract():
    return {
        "version": "1.0.0", "action": "analyze_clips",
        "media": {"duration_ms": 40000},
        "scenes": [
            {"index": 0, "start_ms": 0, "end_ms": 10000},
            {"index": 1, "start_ms": 10000, "end_ms": 20000},
            {"index": 2, "start_ms": 20000, "end_ms": 40000},
        ],
        "configuration": {
            "min_duration_ms": 5000, "target_duration_ms": 10000,
            "max_duration_ms": 20000, "max_candidates": 2,
            "weights": {"duration_fit": 50, "speech_coverage": 30, "boundary_alignment": 20},
        },
    }


def analyze(contract):
    data, config = ClipAnalysisInput.from_contract(contract)
    return DeterministicClipCandidateAnalyzer().analyze(data, config).to_dict()


def test_golden_scene_only_and_complete_provenance():
    contract = golden_contract()
    before = deepcopy(contract)
    result = analyze(contract)
    assert contract == before
    assert result["algorithm"] == "scene_timing_baseline"
    assert result["algorithm_version"] == "1.0.0"
    assert result["parameters"] == {
        "configuration": contract["configuration"],
        "effective_weights": {"duration_fit": 50, "speech_coverage": 0, "boundary_alignment": 0},
        "transcript_used": False,
        "candidate_policy": "whole_scene_non_overlapping",
        "timing_policy": "original_media_ms",
        "transcript_policy": "optional_strict_unshifted",
        "boundary_policy": "strict_interior_speech_cut",
        "score_scale": 1000000, "rounding": "half_up",
        "limits": {"max_scenes": 10000, "max_transcript_segments": 50000,
                   "max_input_bytes": 8388608, "max_duration_ms": 2147483647},
    }
    assert result["candidates"] == [
        {"index": i, "start_ms": i * 10000, "end_ms": (i + 1) * 10000,
         "rank": i + 1, "score": 1,
         "criteria": {"duration_fit": 1, "speech_coverage": 0, "boundary_alignment": 0},
         "source_scene_indexes": [i]}
        for i in range(2)
    ]
    assert analyze(contract) == result


def test_golden_enriched_scores_and_endpoint_alignment():
    contract = golden_contract()
    contract["configuration"]["max_candidates"] = 3
    contract["transcript_segments"] = [
        {"start_ms": 5000, "end_ms": 15000}, {"start_ms": 25000, "end_ms": 35000},
    ]
    result = analyze(contract)
    assert [c["score"] for c in result["candidates"]] == [0.75, 0.75, 0.6]
    assert [c["criteria"] for c in result["candidates"]] == [
        {"duration_fit": 1, "speech_coverage": 0.5, "boundary_alignment": 0.5},
        {"duration_fit": 1, "speech_coverage": 0.5, "boundary_alignment": 0.5},
        {"duration_fit": 0.5, "speech_coverage": 0.5, "boundary_alignment": 1},
    ]
    assert result["parameters"]["effective_weights"] == contract["configuration"]["weights"]
    contract["configuration"]["max_candidates"] = 2
    assert [c["source_scene_indexes"] for c in analyze(contract)["candidates"]] == [[0], [1]]


def test_present_empty_transcript_is_not_unavailable():
    contract = golden_contract()
    absent = analyze(contract)
    contract["transcript_segments"] = []
    present = analyze(contract)
    assert absent["parameters"]["transcript_used"] is False
    assert present["parameters"]["transcript_used"] is True
    assert present["candidates"][0]["score"] == 0.7
    assert present["candidates"][0]["criteria"]["boundary_alignment"] == 1


def test_top_k_precedes_chronological_indexes_and_rank_is_not_index():
    contract = golden_contract()
    contract["configuration"]["target_duration_ms"] = 20000
    result = analyze(contract)["candidates"]
    assert [(c["index"], c["rank"], c["source_scene_indexes"]) for c in result] == [
        (0, 2, [0]), (1, 1, [2]),
    ]


@pytest.mark.parametrize("scenes", [[], [{"index": 0, "start_ms": 0, "end_ms": 4999}],
                                   [{"index": 0, "start_ms": 0, "end_ms": 20001}]])
def test_empty_and_ineligible_scenes_have_no_fallback(scenes):
    contract = golden_contract()
    contract["scenes"] = scenes
    assert analyze(contract)["candidates"] == []
    assert analyze(contract)["parameters"]["configuration"] == contract["configuration"]


def test_gaps_zero_length_segments_and_safe_endpoint_equality():
    contract = golden_contract()
    contract["scenes"] = [{"index": 0, "start_ms": 10000, "end_ms": 20000}]
    contract["transcript_segments"] = [
        {"start_ms": 0, "end_ms": 0}, {"start_ms": 10000, "end_ms": 15000},
        {"start_ms": 15000, "end_ms": 15000}, {"start_ms": 18000, "end_ms": 20000},
    ]
    candidate = analyze(contract)["candidates"][0]
    assert candidate["criteria"] == {"duration_fit": 1, "speech_coverage": 0.7, "boundary_alignment": 1}
    assert candidate["score"] == 0.91


@pytest.mark.parametrize("a,b,expected", [(1, 128, 7813), (1, 3, 333333), (2, 3, 666667),
                                        (2147483647, 2147483647, 1000000)])
def test_integer_half_up_quantization(a, b, expected):
    assert quantize(a, b) == expected


@pytest.mark.parametrize("key,value,count", [
    ("min_duration_ms", 10001, 1), ("max_duration_ms", 10000, 2),
    ("max_candidates", 1, 1), ("target_duration_ms", 20000, 2),
])
def test_configuration_fields_change_selection_and_are_recorded(key, value, count):
    contract = golden_contract()
    contract["configuration"][key] = value
    if key == "min_duration_ms":
        contract["configuration"]["target_duration_ms"] = value
    result = analyze(contract)
    assert len(result["candidates"]) == count
    assert result["parameters"]["configuration"] == contract["configuration"]


@pytest.mark.parametrize("weights,score", [
    ({"duration_fit": 100, "speech_coverage": 30, "boundary_alignment": 20}, 0.8),
    ({"duration_fit": 50, "speech_coverage": 100, "boundary_alignment": 20}, 0.411765),
    ({"duration_fit": 50, "speech_coverage": 30, "boundary_alignment": 100}, 0.833333),
    ({"duration_fit": 50, "speech_coverage": 0, "boundary_alignment": 0}, 1),
])
def test_each_configured_weight_changes_the_score(weights, score):
    contract = golden_contract()
    contract["transcript_segments"] = []
    contract["configuration"]["weights"] = weights
    assert analyze(contract)["candidates"][0]["score"] == score
    original = analyze(contract)["candidates"]
    contract["configuration"]["weights"] = {k: v * 2 for k, v in weights.items()}
    assert analyze(contract)["candidates"] == original


@pytest.mark.parametrize("bad", [True, False, 1.0, "1", None, -1, 0, 2147483648])
def test_invalid_duration_even_with_empty_scenes(bad):
    contract = golden_contract()
    contract["scenes"] = []
    contract["media"]["duration_ms"] = bad
    with pytest.raises(ClipValidationError):
        ClipAnalysisInput.from_contract(contract)


@pytest.mark.parametrize("path,value", [
    (("scenes",), {}), (("scenes",), None), (("transcript_segments",), None),
    (("scenes", 0, "index"), True), (("scenes", 0, "index"), 1),
    (("scenes", 0, "start_ms"), -1), (("scenes", 0, "end_ms"), 0),
    (("scenes", 1, "start_ms"), 9999), (("scenes", 2, "end_ms"), 40001),
    (("configuration", "min_duration_ms"), 0), (("configuration", "target_duration_ms"), 4000),
    (("configuration", "max_candidates"), 1001), (("configuration", "max_candidates"), 2.0),
    (("configuration", "weights", "duration_fit"), 0),
    (("configuration", "weights", "speech_coverage"), -1),
    (("configuration", "weights", "boundary_alignment"), 10001),
    (("version",), "1.1.0"), (("action",), "probe"), (("media", "text"), "PRIVATE_SENTINEL"),
    (("media_asset_id",), 1), (("configuration", "model"), "PRIVATE_SENTINEL"),
])
def test_rejects_input_mutations(path, value):
    contract = golden_contract()
    target = contract
    for key in path[:-1]:
        target = target[key]
    target[path[-1]] = value
    with pytest.raises(ClipValidationError, match="Invalid clip analysis contract"):
        ClipAnalysisInput.from_contract(contract)


@pytest.mark.parametrize("segments", [
    [{"start_ms": 0, "end_ms": 40001}], [{"start_ms": 1.0, "end_ms": 2}],
    [{"start_ms": 3, "end_ms": 2}], [{"start_ms": 0, "end_ms": 10, "text": "PRIVATE_SENTINEL"}],
    [{"start_ms": 0, "end_ms": 10}, {"start_ms": 9, "end_ms": 12}],
])
def test_rejects_malformed_transcript_timings(segments):
    contract = golden_contract()
    contract["transcript_segments"] = segments
    with pytest.raises(ClipValidationError):
        ClipAnalysisInput.from_contract(contract)


@pytest.mark.parametrize("path,value", [
    (("algorithm",), "other"), (("algorithm_version",), ""), (("candidates",), {}),
    (("candidates",), []), (("candidates", 0, "score"), 0.9),
    (("candidates", 0, "score"), float("nan")), (("candidates", 0, "score"), float("inf")),
    (("candidates", 0, "score"), True), (("candidates", 0, "index"), 0.0),
    (("candidates", 0, "rank"), 2), (("candidates", 0, "start_ms"), 1),
    (("candidates", 0, "source_scene_indexes"), [1]),
    (("candidates", 0, "criteria", "speech_coverage"), 0.1),
    (("parameters", "transcript_used"), 0), (("parameters", "rounding"), "bankers"),
    (("parameters", "limits", "max_scenes"), 9999),
])
def test_result_validation_rederives_exact_expected_output(path, value):
    contract = golden_contract()
    data, config = ClipAnalysisInput.from_contract(contract)
    result = analyze(contract)
    target = result
    for key in path[:-1]:
        target = target[key]
    target[path[-1]] = value
    with pytest.raises(ClipValidationError):
        ClipAnalysisResult.from_dict(result, data, config)


def test_limits_accept_exact_counts_and_reject_one_more():
    contract = golden_contract()
    contract["media"]["duration_ms"] = 10000
    contract["scenes"] = [{"index": i, "start_ms": i, "end_ms": i + 1} for i in range(10000)]
    contract["transcript_segments"] = [{"start_ms": 0, "end_ms": 0} for _ in range(50000)]
    assert analyze(contract)["candidates"] == []
    contract["transcript_segments"].append({"start_ms": 0, "end_ms": 0})
    with pytest.raises(ClipValidationError):
        ClipAnalysisInput.from_contract(contract)
    contract.pop("transcript_segments")
    contract["scenes"].append({"index": 10000, "start_ms": 10000, "end_ms": 10001})
    with pytest.raises(ClipValidationError):
        ClipAnalysisInput.from_contract(contract)
