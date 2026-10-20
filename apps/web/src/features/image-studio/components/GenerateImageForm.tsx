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

  const selectClassName =
    'mt-1 block w-full min-h-11 rounded-lg border border-gray-300 dark:border-gray-600 ' +
    'bg-white dark:bg-gray-900 px-3 py-2 text-gray-900 dark:text-white ' +
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ' +
    'focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-900';

  return (
    <form
      onSubmit={handleSubmit}
      aria-label="Generate image"
      className="space-y-4 bg-white dark:bg-gray-800 rounded-xl p-6 shadow-sm"
      noValidate
    >
      <div>
        <label
          htmlFor="image-prompt"
          className="block text-sm font-medium text-gray-900 dark:text-white"
        >
          Prompt
        </label>
        <textarea
          id="image-prompt"
          value={prompt}
          onChange={handlePromptChange}
          maxLength={PROMPT_MAX_LENGTH}
          rows={4}
          aria-describedby="image-prompt-counter image-form-live"
          placeholder="Describe the image you want to create"
          className={
            'mt-1 block w-full min-h-11 rounded-lg border border-gray-300 dark:border-gray-600 ' +
            'bg-white dark:bg-gray-900 px-3 py-2 text-gray-900 dark:text-white placeholder-gray-400 ' +
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ' +
            'focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-900'
          }
        />
        <p
          id="image-prompt-counter"
          className="mt-1 text-right text-xs text-gray-500 dark:text-gray-400 tabular-nums"
        >
          {prompt.length}/{PROMPT_MAX_LENGTH}
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label
            htmlFor="image-style-preset"
            className="block text-sm font-medium text-gray-900 dark:text-white"
          >
            Style preset
          </label>
          <select
            id="image-style-preset"
            value={stylePreset}
            onChange={(event) => setStylePreset(event.target.value)}
            className={selectClassName}
          >
            {STYLE_PRESETS.map((preset) => (
              <option key={preset.id} value={preset.id}>
                {preset.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label
            htmlFor="image-dimensions"
            className="block text-sm font-medium text-gray-900 dark:text-white"
          >
            Dimensions
          </label>
          <select
            id="image-dimensions"
            value={dimensionPresetId}
            onChange={(event) => setDimensionPresetId(event.target.value)}
            className={selectClassName}
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
        className={
          'w-full min-h-11 inline-flex items-center justify-center gap-2 rounded-lg ' +
          'bg-blue-600 px-5 py-2.5 font-medium text-white transition-colors ' +
          'hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60 ' +
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ' +
          'focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-900'
        }
      >
        {generating && (
          <span
            aria-hidden="true"
            className="animate-spin inline-block h-4 w-4 rounded-full border-2 border-white border-t-transparent"
          />
        )}
        Generate
      </button>

      <div
        id="image-form-live"
        aria-live="polite"
        className="min-h-6 text-sm text-red-600 dark:text-red-400"
      >
        {visibleError}
      </div>
    </form>
  );
};
