import { useRef, useEffect, useCallback, useState } from 'react';
import type {
  PublishCampaignRequest,
  SocialPublishCampaignStatus,
} from '../types';
import {
  usePublishCampaign,
  useCancelPublishCampaign,
  useRetryPublishCampaign,
  usePublishCampaignDetail,
} from '../hooks';
import '../design-tokens.css';

type Platform = 'youtube' | 'instagram' | 'tiktok';

const PLATFORM_CONFIG: Record<Platform, { label: string; ariaLabel: string }> = {
  youtube: { label: 'YouTube', ariaLabel: 'Publish to YouTube' },
  instagram: { label: 'Instagram', ariaLabel: 'Publish to Instagram' },
  tiktok: { label: 'TikTok', ariaLabel: 'Publish to TikTok' },
};

const STATUS_LABELS: Record<SocialPublishCampaignStatus, string> = {
  pending: 'Queued',
  processing: 'Publishing',
  published: 'Published',
  partially_published: 'Partially Published',
  failed: 'Failed',
  cancelled: 'Cancelled',
};

interface PublishCampaignDialogProps {
  isOpen: boolean;
  onClose: () => void;
  clipReviewId: number;
  availablePlatforms: Platform[];
  onCampaignCreated?: (campaignId: number) => void;
}

export function PublishCampaignDialog({
  isOpen,
  onClose,
  clipReviewId,
  availablePlatforms = [],
  onCampaignCreated,
}: PublishCampaignDialogProps) {
  const [selectedPlatforms, setSelectedPlatforms] = useState<Platform[]>([]);
  const [platformMetadata, setPlatformMetadata] = useState<Record<Platform, { title: string; description: string }>>(
    { youtube: { title: '', description: '' }, instagram: { title: '', description: '' }, tiktok: { title: '', description: '' } },
  );
  const [formError, setFormError] = useState<string | null>(null);
  const [campaignStatus, setCampaignStatus] = useState<SocialPublishCampaignStatus | null>(null);
  const [targetStates, setTargetStates] = useState<Record<Platform, SocialPublishCampaignStatus>>({
    youtube: 'pending',
    instagram: 'pending',
    tiktok: 'pending',
  });
  const [campaignId, setCampaignId] = useState<number | null>(null);

  const { createCampaign, isPending, error: apiError } = usePublishCampaign();
  const { cancel: cancelCampaign, isPending: cancelPending, error: cancelError } = useCancelPublishCampaign();
  const { retry: retryCampaign, isPending: retryPending, error: retryError } = useRetryPublishCampaign();
  const {
    campaign: campaignDetail,
    fetchCampaign: fetchCampaignDetail,
  } = usePublishCampaignDetail(campaignId || 0);
  const modalRef = useRef<HTMLDivElement>(null);
  const liveRegionRef = useRef<HTMLSpanElement>(null);
  const confirmButtonRef = useRef<HTMLButtonElement>(null);

  const announce = useCallback((message: string) => {
    if (liveRegionRef.current) {
      liveRegionRef.current.textContent = message;
    }
  }, []);

  const focusableElements = useCallback(() => {
    if (!modalRef.current) return [];
    const elements: HTMLElement[] = [];
    const checkboxes = modalRef.current.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach((el) => elements.push(el as HTMLElement));
    const inputs = modalRef.current.querySelectorAll('input[type="text"], textarea');
    inputs.forEach((el) => elements.push(el as HTMLElement));
    if (confirmButtonRef.current) elements.push(confirmButtonRef.current);
    return elements;
  }, []);

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
      setFormError(null);
      setCampaignStatus(null);
      setTargetStates({ youtube: 'pending', instagram: 'pending', tiktok: 'pending' });
      setTimeout(() => {
        const firstCheckbox = modalRef.current?.querySelector('input[type="checkbox"]');
        firstCheckbox?.focus();
      }, 0);
    } else {
      document.body.style.overflow = '';
    }

    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
        return;
      }

      if (e.key === 'Tab') {
        const elements = focusableElements();
        if (elements.length <= 1) return;
        const currentIndex = elements.findIndex((el) => el === document.activeElement);
        if (currentIndex === -1) return;

        if (e.shiftKey) {
          e.preventDefault();
          const prevIndex = currentIndex === 0 ? elements.length - 1 : currentIndex - 1;
          elements[prevIndex].focus();
        } else {
          e.preventDefault();
          const nextIndex = currentIndex === elements.length - 1 ? 0 : currentIndex + 1;
          elements[nextIndex].focus();
        }
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose, focusableElements]);

  // Poll campaign detail after creation so targetStates reflects real per-target status.
  useEffect(() => {
    if (!campaignId || !isOpen) return;

    const isTerminal = (status: SocialPublishCampaignStatus | null) =>
      status === 'published' || status === 'failed' || status === 'cancelled';

    const interval = setInterval(async () => {
      await fetchCampaignDetail();
      const campaign = campaignDetail;
      if (!campaign) return;

      setCampaignStatus(campaign.status);
      const nextTargetStates: Record<Platform, SocialPublishCampaignStatus> = {
        youtube: 'pending',
        instagram: 'pending',
        tiktok: 'pending',
      };
      campaign.publishes.forEach((publish) => {
        if (publish.platform in nextTargetStates) {
          nextTargetStates[publish.platform as Platform] = publish.status as SocialPublishCampaignStatus;
        }
      });
      setTargetStates(nextTargetStates);

      if (isTerminal(campaign.status)) {
        clearInterval(interval);
        announce(`Campaign status: ${STATUS_LABELS[campaign.status as SocialPublishCampaignStatus]}`);
      }
    }, 2000);

    return () => clearInterval(interval);
  }, [campaignId, isOpen, fetchCampaignDetail, campaignDetail, announce]);

  const handleRetryTarget = async (platform: Platform) => {
    if (!campaignId) return;
    const result = await retryCampaign(campaignId);
    if (result) {
      announce(`Retrying ${PLATFORM_CONFIG[platform].label}`);
      await fetchCampaignDetail();
      setCampaignStatus(campaignDetail?.status ?? null);
      const nextTargetStates: Record<Platform, SocialPublishCampaignStatus> = {
        youtube: 'pending',
        instagram: 'pending',
        tiktok: 'pending',
      };
      campaignDetail?.publishes.forEach((publish) => {
        if (publish.platform in nextTargetStates) {
          nextTargetStates[publish.platform as Platform] = publish.status as SocialPublishCampaignStatus;
        }
      });
      setTargetStates(nextTargetStates);
    } else if (retryError) {
      setFormError(retryError);
      announce(retryError);
    }
  };

  const handleRetryFailed = async () => {
    if (!campaignId) return;
    const result = await retryCampaign(campaignId);
    if (result) {
      announce('Retrying failed targets');
      await fetchCampaignDetail();
      setCampaignStatus(campaignDetail?.status ?? null);
      const nextTargetStates: Record<Platform, SocialPublishCampaignStatus> = {
        youtube: 'pending',
        instagram: 'pending',
        tiktok: 'pending',
      };
      campaignDetail?.publishes.forEach((publish) => {
        if (publish.platform in nextTargetStates) {
          nextTargetStates[publish.platform as Platform] = publish.status as SocialPublishCampaignStatus;
        }
      });
      setTargetStates(nextTargetStates);
    } else if (retryError) {
      setFormError(retryError);
      announce(retryError);
    }
  };

  const handleCancel = async () => {
    if (!campaignId) return;
    const result = await cancelCampaign(campaignId);
    if (result) {
      announce('Campaign cancelled');
      setCampaignStatus('cancelled' as SocialPublishCampaignStatus);
      setTargetStates({ youtube: 'cancelled', instagram: 'cancelled', tiktok: 'cancelled' });
    } else if (cancelError) {
      setFormError(cancelError);
      announce(cancelError);
    }
  };

  const togglePlatform = (platform: Platform) => {
    setSelectedPlatforms((prev) =>
      prev.includes(platform) ? prev.filter((p) => p !== platform) : [...prev, platform],
    );
    setFormError(null);
  };

  const handleTitleChange = (platform: Platform, value: string) => {
    setPlatformMetadata((prev) => ({ ...prev, [platform]: { ...prev[platform], title: value } }));
    setFormError(null);
  };

  const handleDescriptionChange = (platform: Platform, value: string) => {
    setPlatformMetadata((prev) => ({ ...prev, [platform]: { ...prev[platform], description: value } }));
    setFormError(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (selectedPlatforms.length === 0) {
      setFormError('Please select at least one platform');
      announce('Please select at least one platform');
      return;
    }

    for (const platform of selectedPlatforms) {
      if (!platformMetadata[platform].title.trim()) {
        setFormError(`Title is required for ${PLATFORM_CONFIG[platform].label}`);
        announce(`Title is required for ${PLATFORM_CONFIG[platform].label}`);
        return;
      }
    }

    setFormError(null);

    const body: PublishCampaignRequest = {
      clip_review_id: clipReviewId,
      targets: selectedPlatforms.map((platform) => ({
        platform,
        title: platformMetadata[platform].title.trim(),
        description: platformMetadata[platform].description.trim() || undefined,
      })),
    };

    const result = await createCampaign(body);

    if (result) {
      setCampaignId(result.campaign_id);
      setCampaignStatus('pending');
      selectedPlatforms.forEach((platform) => setTargetStates({ ...targetStates, [platform]: 'pending' }));
      announce(`Publishing campaign created with ${selectedPlatforms.length} target(s)`);
      onCampaignCreated?.(result.campaign_id);
    } else if (apiError) {
      setFormError(apiError);
      announce(apiError);
    }
  };

  const handleClose = () => {
    setSelectedPlatforms([]);
    setPlatformMetadata({ youtube: { title: '', description: '' }, instagram: { title: '', description: '' }, tiktok: { title: '', description: '' } });
    setFormError(null);
    setCampaignStatus(null);
    setTargetStates({ youtube: 'pending', instagram: 'pending', tiktok: 'pending' });
    setCampaignId(null);
    onClose();
  };

  if (!isOpen) return null;

  const isAnyFailed = selectedPlatforms.some((p) => targetStates[p] === 'failed');
  const isAnyPublished = selectedPlatforms.some((p) => targetStates[p] === 'published');

  return (
    <div
      className="publish-dialog-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="publish-campaign-dialog-title"
    >
      <div
        ref={modalRef}
        tabIndex={-1}
        className="publish-dialog"
      >
        <div className="publish-dialog-header">
          <h2 id="publish-campaign-dialog-title" className="publish-dialog-title">
            Publish to Multiple Platforms
          </h2>
          <button
            type="button"
            onClick={handleClose}
            className="publish-dialog-close"
            aria-label="Close dialog"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {campaignStatus ? (
          <>
            <div className="campaign-status-summary" role="status" aria-live="polite">
              <span className={`status-badge status-badge-${campaignStatus}`}>{STATUS_LABELS[campaignStatus]}</span>
              {isAnyPublished && <span>Some targets published</span>}
              {isAnyFailed && <span>Some targets failed</span>}
            </div>

            <div className="campaign-targets" aria-label="Publish targets">
              {selectedPlatforms.map((platform) => {
                const status = targetStates[platform];
                const config = PLATFORM_CONFIG[platform];
                const isFailed = status === 'failed';
                const isPublished = status === 'published';
                const isPublishing = status === 'processing' || status === 'pending';

                return (
                  <div key={platform} className="campaign-target" data-testid={`campaign-target-${platform}`}>
                    <div className="campaign-target-header">
                      <span className="campaign-target-platform" aria-label={config.ariaLabel}>
                        {config.label}
                      </span>
                      <span className={`target-status-badge target-status-${status}`}>
                        {STATUS_LABELS[status as SocialPublishCampaignStatus] ?? status}
                      </span>
                    </div>
                    <div className="campaign-target-meta">
                      <span className="campaign-target-title">{platformMetadata[platform].title}</span>
                      {platformMetadata[platform].description && (
                        <span className="campaign-target-description">{platformMetadata[platform].description}</span>
                      )}
                    </div>
                    {isFailed && (
                      <button
                        type="button"
                        onClick={() => handleRetryTarget(platform)}
                        className="btn btn-secondary btn-small"
                        aria-label={`Retry ${config.label}`}
                        data-testid={`retry-${platform}`}
                        disabled={retryPending}
                      >
                        Retry
                      </button>
                    )}
                  </div>
                );
              })}
            </div>

            <div className="form-actions">
              <button
                type="button"
                onClick={handleClose}
                className="btn btn-secondary"
                disabled={isPending}
              >
                Close
              </button>
              {isAnyFailed && (
                <button
                  type="button"
                  onClick={handleRetryFailed}
                  className="btn btn-secondary"
                  disabled={isPending || retryPending}
                >
                  Retry Failed
                </button>
              )}
              <button
                type="button"
                onClick={handleCancel}
                className="btn btn-secondary"
                disabled={isPending || cancelPending}
              >
                Cancel
              </button>
              <button
                ref={confirmButtonRef}
                type="button"
                onClick={handleClose}
                className="btn btn-primary"
                disabled={isPending}
                aria-label="Close campaign dialog"
              >
                Done
              </button>
            </div>
          </>
        ) : (
          <>
            <form onSubmit={handleSubmit} className="publish-dialog-form">
              <div className="form-group">
                <label htmlFor="campaign-platforms" className="form-label">
                  Platforms
                </label>
                <div
                  id="campaign-platforms"
                  className="platform-selector"
                  role="group"
                  aria-label="Publish platforms"
                >
                  {availablePlatforms.map((platform) => {
                    const config = PLATFORM_CONFIG[platform];
                    const isSelected = selectedPlatforms.includes(platform);
                    return (
                      <label key={platform} className="platform-option">
                        <input
                          type="checkbox"
                          name="campaign-platforms"
                          value={platform}
                          checked={isSelected}
                          onChange={() => togglePlatform(platform)}
                          aria-label={config.ariaLabel}
                        />
                        {config.label}
                      </label>
                    );
                  })}
                </div>
                {selectedPlatforms.length === 0 && availablePlatforms.length > 0 && (
                  <div className="form-hint">Select at least one platform</div>
                )}
              </div>

              {selectedPlatforms.map((platform) => {
                const config = PLATFORM_CONFIG[platform];
                return (
                  <div key={platform} className="platform-metadata-section" aria-labelledby={`meta-${platform}-title`}>
                    <h3 id={`meta-${platform}-title`} className="platform-metadata-title">
                      {config.label} metadata
                    </h3>
                    <div className="form-group">
                      <label htmlFor={`campaign-title-${platform}`} className="form-label">
                        Title
                      </label>
                      <input
                        id={`campaign-title-${platform}`}
                        type="text"
                        value={platformMetadata[platform].title}
                        onChange={(e) => handleTitleChange(platform, e.target.value)}
                        className="form-input"
                        placeholder={`Title for ${config.label}`}
                        maxLength={255}
                        required
                        aria-required="true"
                        aria-labelledby={`meta-${platform}-title campaign-title-${platform}`}
                      />
                    </div>
                    <div className="form-group">
                      <label htmlFor={`campaign-description-${platform}`} className="form-label">
                        Description
                      </label>
                      <textarea
                        id={`campaign-description-${platform}`}
                        value={platformMetadata[platform].description}
                        onChange={(e) => handleDescriptionChange(platform, e.target.value)}
                        className="form-textarea"
                        placeholder={`Optional description for ${config.label}`}
                        maxLength={1000}
                        rows={3}
                      />
                    </div>
                  </div>
                );
              })}

              {formError && (
                <div className="form-error" role="alert">
                  {formError}
                </div>
              )}

              <div className="form-actions">
                <button
                  type="button"
                  onClick={handleClose}
                  className="btn btn-secondary"
                  disabled={isPending}
                >
                  Cancel
                </button>
                <button
                  ref={confirmButtonRef}
                  type="submit"
                  className="btn btn-primary"
                  disabled={isPending || selectedPlatforms.length === 0}
                  aria-label="Confirm and publish campaign"
                >
                  {isPending ? 'Publishing…' : 'Confirm & Publish'}
                </button>
              </div>
            </form>

            {apiError && (
              <div className="dialog-error" role="alert">
                {apiError}
              </div>
            )}
          </>
        )}

        <span ref={liveRegionRef} className="sr-only" aria-live="polite"></span>
      </div>
    </div>
  );
}
