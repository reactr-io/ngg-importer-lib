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

  const importGalleries = retval => {
    return galleryFilename
      ? retval
        .then(() => processGalleryFile(galleryFilename))
        .then(onGalleryFinished)
        .catch(onGalleryError)
      : retval
  }

  const importAlbums = retval => {
    return albumFilename
      ? retval
        .then(() => processAlbumFile(albumFilename))
        .then(onAlbumFinished)
        .catch(onAlbumError)
      : retval
  }

  const importImages = retval => {
    return imageFilename
      ? retval
        .then(() => processImageFile(imageFilename))
        .then(onImageFinished)
        .catch(onImageError)
      : retval
  }
  
  
  return [importGalleries, importAlbums, importImages].reduce(
    async (retval, fn) => await fn(retval),
    Promise.resolve()
  ).then(onFinished);
}

export default runNggImport
