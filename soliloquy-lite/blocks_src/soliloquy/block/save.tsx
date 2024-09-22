import React from 'react';

import { useBlockProps } from '@wordpress/block-editor';
import { RawHTML } from '@wordpress/element';

import { SaveProps } from '../types';

const Save: React.FC< SaveProps > = ( { attributes } ) => {
	const blockProps = useBlockProps.save();
	const { sliderId } = attributes;
	const shortcode = `[soliloquy id='${ sliderId }' type='gutenberg']`;

	return sliderId !== undefined && sliderId !== null ? (
		<RawHTML { ...blockProps }>{ shortcode }</RawHTML>
	) : null;
};

export default Save;
