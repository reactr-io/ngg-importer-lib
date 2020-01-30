if (process.env.NODE_ENV === 'development') {
  module.exports = require('./src')
} else {
  module.exports = require('./build')
}
