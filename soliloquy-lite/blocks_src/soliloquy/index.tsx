import { registerBlockType } from '@wordpress/blocks';

import './scss/style.scss';
import Edit from './block/edit';
import Save from './block/save';
import metadata from './block.json';
import Icon from './components/icons/Icon';
import { SoliloquyAttributes } from './types';

registerBlockType< SoliloquyAttributes >( metadata.name, {
	...metadata,
	icon: Icon,
	edit: Edit,
	save: Save,
} );
