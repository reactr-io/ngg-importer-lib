import { useState, useEffect } from 'react'
import runNggImport from '../util/runNggImport'

const useNggImporter = props => {
  const {
    galleryFilename,
    albumFilename,
    imageFilename,
    processUrl,
    running = false
  } = props

  const defaultFileStatus = {
    done :     0,
    aborted :  0,
    skipped :  0,
    total :    0,
    finished : false,
    err :      null
  }

  // Hook into state. Note, the "set" methods don't merge
  const [galleryState, setGalleryState] = useState(defaultFileStatus)
  const [albumState, setAlbumState] = useState(defaultFileStatus)
  const [imageState, setImageState] = useState(defaultFileStatus)

  // Create methods which merge updated state
  const updateGalleryState = data => setGalleryState({ ...galleryState, ...data })
  const updateAlbumState = data => setAlbumState({ ...albumState, ...data })
  const updateImageState = data => setImageState({ ...imageState, ...data })

  const effect = () => {
    if (running) {
      runNggImport({
        galleryFilename,
        albumFilename,
        imageFilename,
        processUrl,
        onGalleryUpdate :   updateGalleryState,
        onGalleryError :    err => updateGalleryState({ err }),
        onGalleryFinished : _ => updateGalleryState({ finished : true }),
        onAlbumUpdate :     updateAlbumState,
        onAlbumError :      err => updateAlbumState({ err }),
        onAlbumFinished :   _ => updateAlbumState({ finished : true }),
        onImageUpdate :     updateImageState,
        onImageError :      err => updateImageState({ err }),
        onImageFinished :   _ => updateImageState({ finished : true })
      })
    }
  }

  useEffect(effect, [running])

  return { galleryState, albumState, imageState }
}

export default useNggImporter
