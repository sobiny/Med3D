const path = require('path');

module.exports = {
  entry: path.resolve(__dirname, 'src/scene.js'),
  output: {
    path: path.resolve(__dirname, '../public/static/tv-viewer'),
    filename: 'scene.js',
    clean: true,
  },
  devtool: false,
  mode: 'production',
  stats: 'minimal',
};
