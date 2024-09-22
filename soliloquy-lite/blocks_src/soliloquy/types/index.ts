import { SVGProps } from 'react';

import { BlockSaveProps } from '@wordpress/blocks';

// Type for icon properties.
export type IconProps = SVGProps< SVGSVGElement >;

// Type for logo properties.
export type LogoProps = SVGProps< SVGSVGElement >;

// Type for Soliloquy block attributes.
export type SoliloquyAttributes = {
	sliderId?: number | null;
	title?: string;
	soliloquy_gutenberg_data?: string;
};

// Props for the Edit component.
export type EditProps = {
	clientId: string;
	attributes: SoliloquyAttributes;
	setAttributes?: ( attributes: Partial< SoliloquyAttributes > ) => void;
};

// Combined Props for the Soliloquy component.
export type SoliloquyProps = EditProps;

// Props for the Save component.
export type SaveProps = BlockSaveProps< SoliloquyAttributes >;

// Option type for custom select component.
export type OptionType = {
	value: number;
	label: string;
};

// Props for the EnviraSelect component.
export type EnviraSelectProps = SoliloquyProps & {
	onSelect?: ( newValue: OptionType | null ) => void;
};

// Props for the Inspector component.
export type SoliloquyInspectorProps = EditProps;

// Minimal type for gallery data.
export type MinimalPostObject = {
	id: number;
	title: {
		rendered: string;
	};
	link: string;
};

// Response type for Soliloquy API using the minimal type.
export type SoliloquyResponse = {
	gallery_data: MinimalPostObject[];
};

// Props for the SoliloquyBlock component.
export type SoliloquyBlockProps = EditProps;

// Define the shape of the block attributes.
export type BlockType = {
	attributes: SoliloquyAttributes;
};

// Define the type for the block editor select function.
export type BlockEditorSelect = {
	getBlock: ( clientId: string ) => BlockType | undefined;
};

// Define the structure of the API response data.
export type SoliloquyItem = {
	id: number;
	title: {
		rendered: string;
	};
};

// Define the type for the updateBlockAttributes function.
export type UpdateBlockAttributes = {
	( clientId: string, attributes: SoliloquyAttributes ): void;
};
