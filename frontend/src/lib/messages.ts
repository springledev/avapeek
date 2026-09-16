export const MSG = {
  feedEmpty: 'No photos yet',
  filterEmpty: (name: string) => `No photos of ${name} yet — add the first one!`,
  uploadFailed: "We couldn't add one photo — try that one again",
  viewerError: "This photo didn't load — tap to retry",
  onboardingWelcome: 'Photos of the kids, all in one place.',
  uploadComingSoon: 'Upload is coming soon.',
  seePhotos: 'See photos',
  photosAdded: (n: number) => `${n} ${n === 1 ? 'photo' : 'photos'} added`,
} as const
