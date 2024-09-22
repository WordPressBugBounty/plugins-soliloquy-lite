import React, { useState, useEffect, useRef } from 'react';

import apiFetch from '@wordpress/api-fetch';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import {
	OptionType,
	EnviraSelectProps,
	BlockEditorSelect,
	SoliloquyItem,
	UpdateBlockAttributes,
	SoliloquyAttributes,
} from '../types';
import '../scss/envira-select.scss';

const EnviraSelect: React.FC< EnviraSelectProps > = ( {
	clientId,
	onSelect = () => {},
} ) => {
	const [ selectedOption, setSelectedOption ] = useState< OptionType | null >(
		null
	);
	const [ options, setOptions ] = useState< OptionType[] >( [] );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ isDropdownOpen, setIsDropdownOpen ] = useState( false );
	const [ loading, setLoading ] = useState( false );

	const blockAttributes = useSelect(
		( select: ( key: 'core/block-editor' ) => BlockEditorSelect ) => {
			const block = select( 'core/block-editor' ).getBlock( clientId );
			return block?.attributes ?? { sliderId: null, title: '' };
		},
		[ clientId ]
	);

	const { updateBlockAttributes } = useDispatch< 'core/block-editor' >(
		'core/block-editor'
	) as {
		updateBlockAttributes: UpdateBlockAttributes;
	};

	const dropdownRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		// prettier-ignore
		const initialOption = blockAttributes.sliderId ? { value: blockAttributes.sliderId, label: blockAttributes.title || '' } : null;
		setSelectedOption( initialOption );
		setSearchTerm( '' );
		fetchOptions();
	}, [ blockAttributes ] );

	const fetchOptions = ( query = '' ) => {
		setLoading( true );
		void apiFetch( {
			path: `/wp/v2/soliloquy?per_page=20&search=${ query }`,
		} )
			.then( ( json: unknown ) => {
				const uniqueOptions = Array.from(
					new Map(
						( json as SoliloquyItem[] ).map( ( item ) => [
							item.id,
							{ value: item.id, label: item.title.rendered },
						] )
					).values()
				);
				setOptions( uniqueOptions );
			} )
			.catch( ( error: unknown ) => {
				// eslint-disable-next-line no-console
				console.error( 'Error fetching options:', error );
			} )
			.then( () => {
				setLoading( false );
			} );
	};

	useEffect( () => {
		const handleClickOutside = ( event: MouseEvent ) => {
			if (
				dropdownRef.current &&
				! dropdownRef.current.contains( event.target as Node )
			) {
				setIsDropdownOpen( false );
			}
		};

		document.addEventListener( 'mousedown', handleClickOutside );
		return () => {
			document.removeEventListener( 'mousedown', handleClickOutside );
		};
	}, [ dropdownRef ] );

	const handleInputChange = ( e: React.ChangeEvent< HTMLInputElement > ) => {
		const value = e.target.value;
		setSearchTerm( value );
		setIsDropdownOpen( true );
		if ( value.length > 1 ) {
			fetchOptions( value );
		} else if ( value === '' ) {
			fetchOptions();
		}
	};

	const handleOptionClick = ( option: OptionType ) => {
		setSelectedOption( option );
		setSearchTerm( '' );
		setIsDropdownOpen( false );
		onSelect( option );
		const newAttributes: SoliloquyAttributes = {
			sliderId: option.value,
			title: option.label,
			soliloquy_gutenberg_data: JSON.stringify( {
				sliderId: option.value,
				title: option.label,
			} ),
		};
		updateBlockAttributes( clientId, newAttributes );
		fetchOptions();
	};

	const handleInputClick = () => {
		setIsDropdownOpen( ! isDropdownOpen );
	};

	let placeholderText;
	if ( selectedOption ) {
		placeholderText = selectedOption.label;
	} else {
		placeholderText = __( 'Search for a slider.' );
	}

	return (
		<div
			ref={ dropdownRef }
			className={ `select-with-search ${
				isDropdownOpen ? 'has-dropdown' : ''
			}` }
		>
			<input
				type="text"
				placeholder={ placeholderText }
				value={ searchTerm }
				onChange={ handleInputChange }
				onClick={ handleInputClick }
				className={ `search-input ${
					selectedOption ? 'selected' : ''
				}` }
			/>
			{ isDropdownOpen && (
				<div className="dropdown-menu">
					{ loading ? (
						<div className="dropdown-option loading">
							{ __( 'Loading…' ) }
						</div>
					) : (
						<>
							{ options.length > 0 ? (
								options.map( ( option ) => (
									<div
										key={ option.value }
										className={ `dropdown-option ${
											option.value ===
											selectedOption?.value
												? 'selected'
												: ''
										}` }
										onClick={ () =>
											handleOptionClick( option )
										}
										onKeyDown={ ( e ) => {
											if (
												e.key === 'Enter' ||
												e.key === ' '
											) {
												handleOptionClick( option );
											}
										} }
										role="button"
										tabIndex={ 0 }
									>
										{ option.label }
									</div>
								) )
							) : (
								<div className="dropdown-option no-options">
									{ __( 'No options' ) }
								</div>
							) }
						</>
					) }
				</div>
			) }
		</div>
	);
};

export default EnviraSelect;
