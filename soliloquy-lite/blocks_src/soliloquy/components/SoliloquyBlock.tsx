import React, { useEffect, useRef } from 'react';

import apiFetch from '@wordpress/api-fetch';
import { Placeholder } from '@wordpress/components';
import { useSelect } from '@wordpress/data';

import EnviraSelect from './EnviraSelect';
import Logo from './icons/Logo';
import '../scss/editor.scss';
import {
	OptionType,
	SoliloquyResponse,
	SoliloquyBlockProps,
	BlockEditorSelect,
} from '../types';

const SoliloquyBlock: React.FC< SoliloquyBlockProps > = ( { clientId } ) => {
	const activeSliderRef = useRef<
		SoliloquyResponse[ 'gallery_data' ] | null
	>( null );

	const attributes = useSelect(
		( select: ( key: 'core/block-editor' ) => BlockEditorSelect ) => {
			const block = select( 'core/block-editor' ).getBlock( clientId );
			return block?.attributes ?? { sliderId: null, title: '' };
		},
		[ clientId ]
	);

	useEffect( () => {
		if ( attributes.sliderId ) {
			apiFetch( { path: `/wp/v2/soliloquy/${ attributes.sliderId }` } )
				.then( ( response ) => response as SoliloquyResponse )
				.then( ( response ) => {
					activeSliderRef.current = response.gallery_data;
				} )
				.catch( ( error ) => {
					// eslint-disable-next-line no-console
					console.error( 'Error fetching slider:', error );
				} );
		}
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
		<Placeholder className="soliloquy-block__placeholder">
			<div className="soliloquy-block__placeholder-brand">
				<Logo />
			</div>
			<div className="soliloquy-block-select">
				<EnviraSelect
					clientId={ clientId }
					attributes={ attributes }
					onSelect={ handleSelect }
				/>
			</div>
		</Placeholder>
	);
};

export default SoliloquyBlock;
