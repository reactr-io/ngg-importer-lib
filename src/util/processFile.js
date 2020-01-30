import { isObject } from 'util'

const axios = require('axios').default

/**
 * Calls the notifiying function, but returns the original data
 */
const notify = f => data => {
  f(data)
  return data
}

/**
 * If predicate(data) evaluates to TRUE, return f(params); otherwise return data
 */
const maybeCall = (f, params, predicate) => data => {
  if (predicate(data)) return f(params)
  return data
}

/**
 * Gets the value of a property from an object
 */
const prop = key => obj => obj[key]

/**
 * Determines if the importer has finished
 */
const isFinished = data => data.total === (data.done + data.aborted + data.skipped)

/**
 * Determines if the importer has not yet finished processing the file
 */
const isNotFinished = data => !isFinished(data)

/**
 * Will throw an error if the response contains one
 */
const throwErrorDetails = data => {
  if (!data || !isObject(data)) {
    const err = new Error('E_NGG_IMPORTER')
    err.context = {
      error_code : 'Fatal',
      error_msg :  'An unexpected error occured. Invalid response',
      response : data
    }
    throw err
  } else if (data.error_code) {
    const err = new Error('E_NGG_IMPORTER')
    err.context = data
    throw err
  }
  return data
}

/**
 * Processes a file to be imported
 */
const processFile = (params) => {
  const { filename, processUrl, onUpdate, onError } = params

  return axios.post(processUrl, { filename })
    .then(prop('data'))
    .then(throwErrorDetails)
    .then(notify(onUpdate))
    .then(maybeCall(processFile, params, isNotFinished))
    .catch(onError)
}

export default processFile
