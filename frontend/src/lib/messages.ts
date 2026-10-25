const UPLOAD_FAILED = "We couldn't add one photo — try that one again"

export const MSG = {
  feedEmpty: 'No photos yet',
  filterEmpty: (name: string) => `No photos of ${name} yet — add the first one!`,
  uploadFailed: UPLOAD_FAILED,
  uploadPartial: (done: number, total: number) =>
    `${done} of ${total} added — ${UPLOAD_FAILED}`,
  viewerError: "This photo didn't load — tap to retry",
  onboardingWelcome: 'Photos of the kids, all in one place.',
  uploadComingSoon: 'Upload is coming soon.',
  seePhotos: 'See photos',
  photosAdded: (n: number) => `${n} ${n === 1 ? 'photo' : 'photos'} added`,
  commentPostFailed: "Couldn't post that — try again",
  commentDeleteFailed: "Couldn't delete that — try again",
  downloadFailed: "Couldn't download that photo — try again",
  commentsLoadFailed: "Couldn't load comments — try again",
  commentTooLong: 'Comments are limited to 500 characters.',
  setupSkip: 'Skip',
  setupStepNames: ['Add your kid', 'Add a first photo', 'All set'],
  setupKidTitle: 'Add your kid',
  setupKidHint: "Photos live in a kid's album. You can add more kids later.",
  setupPhotoTitle: 'Add a first photo',
  setupPhotoHint: 'Pick a photo or two. They are encrypted on this device before upload.',
  setupPhotoNeedsKid: 'Photos need a kid to live with. Add a kid first.',
  setupAddKid: 'Add a kid',
  setupWhoFor: 'Who are these photos of?',
  setupDoneTitle: 'All set',
  setupDoneKid: (name: string) => `Added ${name}`,
  setupDonePhotos: (n: number) => `${n} ${n === 1 ? 'photo' : 'photos'} added`,
  setupSkippedKidNote: 'You skipped adding a kid. Photos need a kid, so add one before uploading.',
  setupDoLater: 'Do later',
  setupDoLaterKid: 'Add a kid',
  setupDoLaterPhoto: 'Add photos',
  setupGoToFeed: 'Go to the feed',
  setupInviteFamily: 'Invite family',
  setupFinishTitle: 'Finish setting up',
  setupFinishBody: 'Add a kid and a first photo to get the most out of Avapeek.',
  setupFinishAction: 'Continue setup',
  setupDismiss: 'Dismiss',
  selectPhotos: 'Select',
  selectCancel: 'Cancel',
  selectedCount: (n: number) => `${n} selected`,
  selectAllIn: (label: string) => `Select all in ${label}`,
  deselectAllIn: (label: string) => `Deselect all in ${label}`,
  bulkDownload: 'Download',
  bulkTags: 'Tags',
  bulkShare: 'Share to...',
  bulkRemove: 'Remove',
  bulkCancelAction: 'Cancel',
  bulkRemoveTitle: (n: number) => `Remove ${n} ${n === 1 ? 'photo' : 'photos'}?`,
  bulkRemoveBody:
    'Photos that are also in kids you do not manage are only removed from the kids you manage. They stay for everyone else.',
  bulkRemoveConfirm: 'Remove',
  bulkRemoved: (deleted: number, detached: number, forbidden: number) =>
    [
      deleted > 0 ? `${deleted} deleted` : '',
      detached > 0 ? `${detached} removed from your kids` : '',
      forbidden > 0 ? `${forbidden} could not be removed (you do not manage them)` : '',
    ]
      .filter(Boolean)
      .join(', ') || 'Nothing was removed',
  bulkDownloaded: (saved: number, failed: number) =>
    failed > 0 ? `${saved} downloaded, ${failed} failed` : `${saved} downloaded`,
  bulkStopped: (done: number, left: number) => `Stopped. ${done} done, ${left} not done`,
  bulkFailedPartial: (done: number, left: number) =>
    `Something went wrong. ${done} done, ${left} not done`,
  bulkTagsTitle: 'Add tags',
  bulkTagsHint: 'Tags are added to each photo for every kid it belongs to.',
  bulkTagsApply: 'Add tags',
  bulkTagsDone: (n: number) => `Tagged ${n} ${n === 1 ? 'photo' : 'photos'}`,
  bulkTagsSkipped: (n: number) => `${n} could not be tagged`,
  bulkTagInputLabel: 'Tag name',
  bulkTagAdd: 'Add',
  bulkShareTitle: 'Share to a kid',
  bulkShareHint:
    'Everyone in that kid can then see these photos and their comments. Tags from the original kids do not carry over to that kid\'s members.',
  bulkShareNone: 'These photos are already in all of your kids.',
  bulkShared: (shared: number, skipped: number, unwrappable: number) =>
    [
      `${shared} shared`,
      skipped > 0 ? `${skipped} already there` : '',
      unwrappable > 0 ? `${unwrappable} could not be shared` : '',
    ]
      .filter(Boolean)
      .join(', '),
  bulkActionFailed: "That didn't work — try again",
} as const
