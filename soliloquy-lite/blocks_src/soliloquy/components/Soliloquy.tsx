import React, { Fragment } from 'react';

import { useSelect } from '@wordpress/data';

import SoliloquyBlock from './SoliloquyBlock';
import SoliloquyInspector from './SoliloquyInspector';
import {
	SoliloquyAttributes,
	SoliloquyProps,
	BlockType,
	BlockEditorSelect,
} from '../types';

const Soliloquy: React.FC< SoliloquyProps > = ( { clientId } ) => {
	const { attributes } = useSelect(
		( select: ( key: 'core/block-editor' ) => BlockEditorSelect ) => {
			const block = select( 'core/block-editor' ).getBlock( clientId );

			// Ensure block is not undefined and properly typed.
			const safeBlock: BlockType | undefined = block;
			return {
				attributes: safeBlock?.attributes ?? {
					sliderId: null,
					title: '',
				},
			};
		},
		[ clientId ]
	);

	// Ensure attributes is always defined.
	const safeAttributes: SoliloquyAttributes = attributes || {
		sliderId: null,
		title: '',
	};

	const renderControls = () => {
		return (
			<SoliloquyInspector
				clientId={ clientId }
				attributes={ safeAttributes }
			/>
		);
	};

	const renderBlock = () => {
		return (
			<SoliloquyBlock
				clientId={ clientId }
				attributes={ safeAttributes }
			/>
		);
	};

	return (
		<Fragment>
			<div key="block">{ renderBlock() }</div>
			<div key="controls">{ renderControls() }</div>
		</Fragment>
	);
};

export default Soliloquy;
