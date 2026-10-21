import React, { useEffect, useState } from 'react';
import {
  DEFAULT_DIMENSION_PRESET,
  DEFAULT_STYLE_PRESET,
  DIMENSION_PRESETS,
  PROMPT_MAX_LENGTH,
  STYLE_PRESETS,
} from '../types';

export interface GenerateImageFormSubmitValues {
  prompt: string;
  stylePreset: string;
  width: number;
  height: number;
}

interface GenerateImageFormProps {
  onGenerate: (values: GenerateImageFormSubmitValues) => void | Promise<void>;
  generating: boolean;
  error: string | null;
}

/*
 * Prompt-driven generation form: labeled textarea with a live N/1000
 * counter, fixed style/dimension preset selects, and a Generate button
 * that is disabled while the prompt is empty/whitespace-only or a
 * generation is in flight. Validation errors and server errors are
 * announced through a polite live region.
 */
export const GenerateImageForm: React.FC<GenerateImageFormProps> = ({
  onGenerate,
  generating,
  error,
}) => {
  const [prompt, setPrompt] = useState('');
  const [stylePreset, setStylePreset] = useState(DEFAULT_STYLE_PRESET);
  const [dimensionPresetId, setDimensionPresetId] = useState(DEFAULT_DIMENSION_PRESET);
  const [validationError, setValidationError] = useState<string | null>(null);
  const [dismissedError, setDismissedError] = useState(false);

  // A new server error always surfaces; typing dismisses it until the
  // next one arrives.
  useEffect(() => {
    setDismissedError(false);
  }, [error]);

  const dimensionPreset = DIMENSION_PRESETS.find(
    (preset) => preset.id === dimensionPresetId
  ) ?? DIMENSION_PRESETS[0];

  const visibleError = validationError ?? (dismissedError ? null : error);

  const handlePromptChange = (event: React.ChangeEvent<HTMLTextAreaElement>) => {
    // Mirror browser maxLength enforcement for pasted content: truncate.
    setPrompt(event.target.value.slice(0, PROMPT_MAX_LENGTH));
    setValidationError(null);
    setDismissedError(true);
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    // Defense in depth: the disabled button already prevents submit
    // attempts while empty or in flight; the guard announces the reason.
    if (generating) {
      return;
    }

    if (prompt.trim() === '') {
      setValidationError('Please enter a prompt before generating.');
      return;
    }

    setValidationError(null);

    // The mutation owner surfaces failures through its error state, which
    // is rendered in the polite live region. Await nothing here, but never
    // leave a returned promise unhandled: an unhandled rejection would
    // surface as a browser pageerror.
    void Promise.resolve(
      onGenerate({
        prompt,
        stylePreset,
        width: dimensionPreset.width,
        height: dimensionPreset.height,
      })
    ).catch(() => undefined);
  };

  const canGenerate = prompt.trim() !== '' && !generating;

  return (
    <form
      onSubmit={handleSubmit}
      aria-label="Generate image"
      className="image-studio-form"
      noValidate
    >
      <div className="image-studio-field">
        <label htmlFor="image-prompt">Prompt</label>
        <textarea
          id="image-prompt"
          value={prompt}
          onChange={handlePromptChange}
          maxLength={PROMPT_MAX_LENGTH}
          rows={4}
          aria-describedby="image-prompt-counter image-form-live"
          placeholder="Describe the image you want to create"
          className="image-studio-field-control"
        />
        <p id="image-prompt-counter" className="image-studio-prompt-counter">
          {prompt.length}/{PROMPT_MAX_LENGTH}
        </p>
      </div>

      <div className="image-studio-field-row">
        <div className="image-studio-field">
          <label htmlFor="image-style-preset">Style preset</label>
          <select
            id="image-style-preset"
            value={stylePreset}
            onChange={(event) => setStylePreset(event.target.value)}
            className="image-studio-field-control"
          >
            {STYLE_PRESETS.map((preset) => (
              <option key={preset.id} value={preset.id}>
                {preset.label}
              </option>
            ))}
          </select>
        </div>

        <div className="image-studio-field">
          <label htmlFor="image-dimensions">Dimensions</label>
          <select
            id="image-dimensions"
            value={dimensionPresetId}
            onChange={(event) => setDimensionPresetId(event.target.value)}
            className="image-studio-field-control"
          >
            {DIMENSION_PRESETS.map((preset) => (
              <option key={preset.id} value={preset.id}>
                {preset.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <button
        type="submit"
        disabled={!canGenerate}
        aria-busy={generating}
        className="image-studio-submit-button"
      >
        {generating && (
          <span aria-hidden="true" className="image-studio-submit-spinner" />
        )}
        Generate
      </button>

      <div id="image-form-live" aria-live="polite" className="image-studio-form-live">
        {visibleError}
      </div>
    </form>
  );
};
