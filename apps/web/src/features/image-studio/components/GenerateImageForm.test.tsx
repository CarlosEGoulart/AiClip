import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { GenerateImageForm } from './GenerateImageForm';

/*
 * TC-FE-02 / TC-FE-03 / TC-FE-05 (test-plan §5), form scope.
 *
 * GenerateImageForm is a pure component: submit behavior, preset selects,
 * the disabled-while-loading Generate button, the 1000-char prompt counter,
 * and the polite live-region announcements are verified with plain props —
 * no hooks are involved at this level.
 */

const onGenerate = vi.fn();

const renderForm = (props: { generating?: boolean; error?: string | null } = {}) =>
  render(
    <GenerateImageForm
      onGenerate={onGenerate}
      generating={props.generating ?? false}
      error={props.error ?? null}
    />
  );

describe('GenerateImageForm', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    cleanup();
  });

  it('renders a labeled prompt textarea with maxLength 1000 and a live 0/1000 counter', () => {
    renderForm();

    const textarea = screen.getByLabelText('Prompt');
    expect(textarea).toBeInTheDocument();
    expect(textarea).toHaveAttribute('maxlength', '1000');
    expect(screen.getByText('0/1000')).toBeInTheDocument();
  });

  it('renders the style preset select with the four catalog options and Photorealistic default', () => {
    renderForm();

    const styleSelect = screen.getByLabelText('Style preset');
    expect(styleSelect).toHaveValue('photorealistic');

    const options = within(styleSelect).getAllByRole('option');
    expect(options.map((option) => option.textContent)).toEqual([
      'Photorealistic',
      'Illustration',
      'Cinematic',
      'Digital Art',
    ]);
  });

  it('renders the dimension preset select with the three presets and Square default', () => {
    renderForm();

    const dimensionSelect = screen.getByLabelText('Dimensions');
    expect(dimensionSelect).toHaveValue('square');

    const options = within(dimensionSelect).getAllByRole('option');
    expect(options.map((option) => option.textContent)).toEqual([
      'Square',
      'Vertical 9:16',
      'Landscape 16:9',
    ]);
  });

  it('renders the Generate button with an accessible name', () => {
    renderForm();

    expect(screen.getByRole('button', { name: 'Generate' })).toBeInTheDocument();
  });

  it('calls onGenerate once with the current prompt and preset values', async () => {
    renderForm();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A neon-lit city skyline at dusk');
    await userEvent.selectOptions(screen.getByLabelText('Style preset'), 'cinematic');
    await userEvent.selectOptions(screen.getByLabelText('Dimensions'), 'vertical');
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }));

    expect(onGenerate).toHaveBeenCalledTimes(1);
    expect(onGenerate).toHaveBeenCalledWith({
      prompt: 'A neon-lit city skyline at dusk',
      stylePreset: 'cinematic',
      width: 1080,
      height: 1920,
    });
  });

  it('updates the prompt counter as the user types', async () => {
    renderForm();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor');

    expect(screen.getByText('14/1000')).toBeInTheDocument();
  });

  it('disables the Generate button with a spinner and aria-busy while generating', async () => {
    const view = renderForm();
    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor at dawn');

    view.rerender(
      <GenerateImageForm onGenerate={onGenerate} generating={true} error={null} />
    );

    const button = screen.getByRole('button', { name: 'Generate' });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(button.querySelector('.animate-spin')).not.toBeNull();

    await userEvent.click(button);
    expect(onGenerate).not.toHaveBeenCalled();
  });

  it('does not submit for an empty prompt and announces the validation error politely', () => {
    renderForm();

    const form = screen.getByLabelText('Prompt').closest('form');
    expect(form).not.toBeNull();
    fireEvent.submit(form as HTMLFormElement);

    expect(onGenerate).not.toHaveBeenCalled();

    const liveRegion = document.querySelector('[aria-live="polite"]');
    expect(liveRegion?.textContent).toMatch(/enter a prompt before generating/i);
  });

  it('does not submit for a whitespace-only prompt and announces the validation error', async () => {
    renderForm();

    await userEvent.type(screen.getByLabelText('Prompt'), '   ');
    const form = screen.getByLabelText('Prompt').closest('form');
    fireEvent.submit(form as HTMLFormElement);

    expect(onGenerate).not.toHaveBeenCalled();
    expect(screen.getByText(/enter a prompt before generating/i)).toBeInTheDocument();
  });

  it('clears the validation error when the user types a valid prompt', async () => {
    renderForm();

    const form = screen.getByLabelText('Prompt').closest('form');
    fireEvent.submit(form as HTMLFormElement);
    expect(screen.getByText(/enter a prompt before generating/i)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor');
    expect(screen.queryByText(/enter a prompt before generating/i)).not.toBeInTheDocument();
  });

  it('truncates a 1001-character paste to the 1000-character maximum', () => {
    renderForm();

    fireEvent.change(screen.getByLabelText('Prompt'), {
      target: { value: 'a'.repeat(1001) },
    });

    expect(screen.getByLabelText('Prompt')).toHaveValue('a'.repeat(1000));
    expect(screen.getByText('1000/1000')).toBeInTheDocument();
  });

  it('surfaces a readable server error in the polite live region', () => {
    renderForm({ error: 'The prompt field is required.' });

    const liveRegion = document.querySelector('[aria-live="polite"]');
    expect(liveRegion?.textContent).toContain('The prompt field is required.');
  });

  it('dismisses the server error when the user types a valid prompt', async () => {
    renderForm({ error: 'Too many attempts.' });

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor');

    expect(screen.queryByText('Too many attempts.')).not.toBeInTheDocument();
  });

  it('reaches the controls in logical tab order: textarea, style, dimensions, Generate', async () => {
    renderForm();

    // Type a valid prompt first so the Generate button is enabled and
    // therefore focusable (disabled controls are skipped by Tab).
    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor');

    const textarea = screen.getByLabelText('Prompt');
    const styleSelect = screen.getByLabelText('Style preset');
    const dimensionSelect = screen.getByLabelText('Dimensions');
    const button = screen.getByRole('button', { name: 'Generate' });

    // DOM order defines the sequential focus order for these controls.
    const following = Node.DOCUMENT_POSITION_FOLLOWING;
    expect(textarea.compareDocumentPosition(styleSelect) & following).toBeTruthy();
    expect(styleSelect.compareDocumentPosition(dimensionSelect) & following).toBeTruthy();
    expect(dimensionSelect.compareDocumentPosition(button) & following).toBeTruthy();

    textarea.focus();
    await userEvent.tab();
    expect(styleSelect).toHaveFocus();
    await userEvent.tab();
    expect(dimensionSelect).toHaveFocus();
    await userEvent.tab();
    expect(button).toHaveFocus();
  });

  it('submits via the Generate button when Enter is pressed on it', async () => {
    renderForm();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor at dawn');
    const button = screen.getByRole('button', { name: 'Generate' });
    button.focus();
    await userEvent.keyboard('{Enter}');

    expect(onGenerate).toHaveBeenCalledTimes(1);
    expect(onGenerate).toHaveBeenCalledWith({
      prompt: 'A quiet harbor at dawn',
      stylePreset: 'photorealistic',
      width: 1024,
      height: 1024,
    });
  });

  it('gives every interactive control a 44px minimum height class and a visible focus indicator', () => {
    renderForm();

    const controls = [
      screen.getByLabelText('Prompt'),
      screen.getByLabelText('Style preset'),
      screen.getByLabelText('Dimensions'),
      screen.getByRole('button', { name: 'Generate' }),
    ];

    for (const control of controls) {
      expect(control).toHaveClass('min-h-11');
      expect(control.className).toMatch(/focus-visible:ring-2/);
    }
  });
});
