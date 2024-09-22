const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaultConfig,
  entry: './blocks_src/soliloquy/index.tsx',
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, './blocks/soliloquy'),
		filename: 'index.js',
  },
  resolve: {
    ...defaultConfig.resolve,
    extensions: ['.ts', '.tsx', '.js', '.jsx', ...defaultConfig.resolve.extensions],
  },
  module: {
    ...defaultConfig.module,
    rules: [
      ...defaultConfig.module.rules,
      {
        test: /\.tsx?$/,
        use: 'babel-loader',
        exclude: /node_modules/,
      },
    ],
  },
};
