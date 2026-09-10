# Implementation Plan: Issue #11

> Historical recovery note:
> This document was reconstructed after Issue #11 had already been merged.
> It restores the required SDD artifact structure and does not claim that this
> plan existed before the original implementation.

## Issue

#11 - fix(governance): finalize merge gate with APPROVE-only, deterministic evidence, and TDD N/A reason

## Overview

Fix four semantic defects in governance enforcement that weaken the merge gate.

## Phase 1: RED

Added 7 tests proving defects: `validate_merge_approval` import error (5 tests), bare TDD N/A passes incorrectly (2 tests).

## Phase 2: GREEN

1. Added `validate_merge_approval()` requiring APPROVE specifically
2. Updated TDD N/A pattern to require meaningful reason
3. Fixed `find_evidence_file()` to sort and reject multiple matches
4. Updated `pr_enforcement.py` to use merge approval check

## Phase 3: REFACTOR

Cleaned up evidence file resolution logic with sorted iteration. Tests remain green.

## Commit Strategy

Single commit: `fix(governance): finalize merge gate with APPROVE-only, deterministic evidence, and TDD N/A reason`
