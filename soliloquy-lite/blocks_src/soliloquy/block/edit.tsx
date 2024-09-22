import React, { FC } from 'react';

import { useBlockProps } from '@wordpress/block-editor';

import Soliloquy from '../components/Soliloquy';
import { EditProps } from '../types';

const Edit: FC< EditProps > = ( { clientId, attributes, setAttributes } ) => {
	const blockProps = useBlockProps();
	return (
		<div { ...blockProps }>
			<Soliloquy
				clientId={ clientId }
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
		</div>
	);
};

export default Edit;
