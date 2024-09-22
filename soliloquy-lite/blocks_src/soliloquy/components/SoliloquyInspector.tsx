import React, { useEffect, useRef } from 'react';

import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import EnviraSelect from './EnviraSelect';
import {
	OptionType,
	SoliloquyInspectorProps,
	BlockEditorSelect,
} from '../types';

const SoliloquyInspector: React.FC< SoliloquyInspectorProps > = ( {
	clientId,
} ) => {
	const hasSliderRef = useRef< boolean >( false );

	const attributes = useSelect(
		( select: ( key: 'core/block-editor' ) => BlockEditorSelect ) => {
			const block = select( 'core/block-editor' ).getBlock( clientId );
			return block?.attributes ?? { sliderId: null, title: '' };
		},
		[ clientId ]
	);

	useEffect( () => {
		hasSliderRef.current = attributes.sliderId !== null;
	}, [ attributes.sliderId ] );

	const handleSelect = ( option: OptionType | null ) => {
		if ( option === null ) {
			// eslint-disable-next-line no-console
			console.log( 'No option selected' );
			return;
		}
		// eslint-disable-next-line no-console
		console.log( 'Selected option:', option );
	};

	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Slider' ) }
				className="soliloquy-inspector-panelbody"
			>
				<h3>{ __( 'Search for a Slider' ) }</h3>
				<EnviraSelect
					clientId={ clientId }
					attributes={ attributes }
					onSelect={ handleSelect }
				/>
			</PanelBody>
		</InspectorControls>
	);
};

export default SoliloquyInspector;
