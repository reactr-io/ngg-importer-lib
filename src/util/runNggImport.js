import processFile from './processFile'

/**
 * Runs the NGG import process for the provided files
 */
const runNggImport = params => {
  const {
    galleryFilename,
    albumFilename,
    imageFilename,
    processUrl,
    onGalleryUpdate,
    onGalleryFinished,
    onGalleryError,
    onAlbumUpdate,
    onAlbumFinished,
    onAlbumError,
    onImageUpdate,
    onImageFinished,
    onImageError,
    onFinished
  } = params

  const processGalleryFile = filename => processFile({
    filename,
    processUrl,
    onUpdate : onGalleryUpdate,
    onError :  onGalleryError
  })

  const processAlbumFile = filename => processFile({
    filename,
    processUrl,
    onUpdate : onAlbumUpdate,
    onError :  onAlbumError
  })

  const processImageFile = filename => processFile({
    filename,
    processUrl,
    onUpdate : onImageUpdate,
    onError :  onImageError
  })

  const retval = Promise.resolve()

  // Import galleries
  if (galleryFilename) {
    retval
      .then(() => processGalleryFile(galleryFilename))
      .then(onGalleryFinished)
      .catch(onGalleryError)
  }

  // Import albums
  if (albumFilename) {
    retval
      .then(() => processAlbumFile(albumFilename))
      .then(onAlbumFinished)
      .catch(onAlbumError)
  }

  // Import images
  if (imageFilename) {
    retval
      .then(() => processImageFile(imageFilename))
      .then(onImageFinished)
      .catch(onImageError)
  }

  return retval
    .then(onFinished)
}

export default runNggImport
