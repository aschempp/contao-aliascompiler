<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * TYPOlight Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Andreas Schempp 2010
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 * @version    $Id$
 */


/**
 * Config
 */
$GLOBALS['TL_DCA']['tl_page']['config']['onload_callback'][] = array('tl_page_aliascompiler', 'enableFolderSupport');


/**
 * Palettes
 */
foreach( $GLOBALS['TL_DCA']['tl_page']['palettes'] as $name => $palette )
{
	if ($name == '__selector__')
		continue;
		
	$GLOBALS['TL_DCA']['tl_page']['palettes'][$name] = preg_replace('@([,;])alias([,;])@', '$1alias_prefix,alias_suffix$2', $palette);
}


/**
 * Fields
 */

$GLOBALS['TL_DCA']['tl_page']['fields']['alias_prefix'] = array
(
	'label'			=> &$GLOBALS['TL_LANG']['tl_page']['alias_prefix'],
	'exclude'		=> true,
	'inputType'		=> 'text',
	'eval'			=> array('rgxp'=>'alnum', 'doNotCopy'=>true, 'spaceToUnderscore'=>true, 'maxlength'=>64, 'tl_class'=>'w50'),
	'save_callback'	=> array
	(
		array('tl_page_aliascompiler', 'generateAliasFromPrefix')
	),
);


$GLOBALS['TL_DCA']['tl_page']['fields']['alias_suffix'] = array
(
	'label'			=> &$GLOBALS['TL_LANG']['tl_page']['alias_suffix'],
	'exclude'		=> true,
	'inputType'		=> 'text',
	'eval'			=> array('rgxp'=>'alnum', 'doNotCopy'=>true, 'spaceToUnderscore'=>true, 'maxlength'=>64, 'tl_class'=>'w50'),
	'save_callback'	=> array
	(
		array('tl_page_aliascompiler', 'generateAliasFromSuffix')
	),
);


class tl_page_aliascompiler extends Backend
{
	
	public function enableFolderSupport()
	{
		if (in_array('folderurl', $this->Config->getActiveModules()))
		{
			$GLOBALS['TL_DCA']['tl_page']['fields']['alias_prefix']['eval']['rgxp'] = 'url';
			$GLOBALS['TL_DCA']['tl_page']['fields']['alias_suffix']['eval']['rgxp'] = 'url';
		}
	}
	
	
	public function generateAliasFromSuffix($varValue, DataContainer $dc)
	{
		// Inherit suffix from parent page
		$objPage = $dc->activeRecord;
		$prefix = $objPage->alias_prefix;
		
		while( !strlen($prefix) && $objPage->pid > 0 )
		{
			$objPage = $this->Database->prepare("SELECT * FROM tl_page WHERE id=?")->limit(1)->execute($objPage->pid);
			$prefix = $objPage->alias_prefix;
		}
		
		$autoAlias = false;
		
		// try alias field if alias_suffix is empty
		if (!strlen($varValue) && !strlen($dc->activeRecord->alias_prefix))
		{
			$varValue = str_replace($prefix, '', $dc->activeRecord->alias);
		}

		// Generate alias if there is none
		if (!strlen($varValue) && !strlen($dc->activeRecord->alias_prefix))
		{
			$autoAlias = true;
			$varValue = standardize($dc->activeRecord->title);
		}

		$objAlias = $this->Database->prepare("SELECT id FROM tl_page WHERE id=? OR alias=?")
								   ->execute($dc->id, $prefix.$varValue);

		// Check whether the page alias exists
		if ($objAlias->numRows > 1)
		{
			$arrDomains = array();

			while ($objAlias->next())
			{
				$_pid = $objAlias->id;
				$_type = '';

				do
				{
					$objParentPage = $this->Database->prepare("SELECT id, pid, alias, type, dns FROM tl_page WHERE id=?")
													->limit(1)
													->execute($_pid);

					if ($objParentPage->numRows < 1)
					{
						break;
					}

					$_pid = $objParentPage->pid;
					$_type = $objParentPage->type;
				}
				while ($_pid > 0 && $_type != 'root');

				$arrDomains[] = ($objParentPage->numRows && ($objParentPage->type == 'root' || $objParentPage->pid > 0)) ? $objParentPage->dns : '';
			}

			$arrUnique = array_unique($arrDomains);

			if (count($arrDomains) != count($arrUnique))
			{
				if (!$autoAlias)
				{
					throw new Exception(sprintf($GLOBALS['TL_LANG']['ERR']['aliasExists'], $prefix.$varValue));
				}

				$varValue .= 'id-' . $dc->id;
			}
		}
		
		$this->Database->prepare("UPDATE tl_page SET alias=? WHERE id=?")->execute(rtrim($prefix.$varValue, '/'), $dc->id);

		return $varValue;
	}
	
	
	public function generateAliasFromPrefix($varValue, DataContainer $dc)
	{
		// Inherit suffix from parent page
		$objPage = $dc->activeRecord;
		$prefix = $varValue;
		
		while( !strlen($prefix) && $objPage->pid > 0 )
		{
			$objPage = $this->Database->prepare("SELECT * FROM tl_page WHERE id=?")->limit(1)->execute($objPage->pid);
			$prefix = $objPage->alias_prefix;
		}
		
		$this->generateAlias($prefix, $dc->activeRecord);
		
		return $varValue;
	}
	
	
	private function generateAlias($prefix, $objPage)
	{
		$varValue = $objPage->alias_suffix;
		
		// try alias field if alias_suffix is empty
		if (!strlen($varValue) && !strlen($objPage->alias_prefix))
		{
			$varValue = $objPage->alias;
		}
		
		// Generate alias if there is none
		if (!strlen($varValue) && !strlen($objPage->alias_prefix))
		{
			$varValue = standardize($objPage->title);
		}
		
		$objAlias = $this->Database->prepare("SELECT id FROM tl_page WHERE id=? OR alias=?")
								   ->execute($objPage->id, $prefix.$varValue);

		// Check whether the page alias exists
		if ($objAlias->numRows > 1)
		{
			$arrDomains = array();

			while ($objAlias->next())
			{
				$_pid = $objAlias->id;
				$_type = '';

				do
				{
					$objParentPage = $this->Database->prepare("SELECT id, pid, alias, type, dns FROM tl_page WHERE id=?")
													->limit(1)
													->execute($_pid);

					if ($objParentPage->numRows < 1)
					{
						break;
					}

					$_pid = $objParentPage->pid;
					$_type = $objParentPage->type;
				}
				while ($_pid > 0 && $_type != 'root');

				$arrDomains[] = ($objParentPage->numRows && ($objParentPage->type == 'root' || $objParentPage->pid > 0)) ? $objParentPage->dns : '';
			}

			$arrUnique = array_unique($arrDomains);

			if (count($arrDomains) != count($arrUnique))
			{
				$varValue .= 'id-' . $objPage->id;
			}
		}
		
		$this->Database->prepare("UPDATE tl_page SET alias=?, alias_suffix=? WHERE id=?")->execute(rtrim($prefix.$varValue, '/'), rtrim($varValue, '/'), $objPage->id);

		
		// Generate child page aliases
		$objSubpages = $this->Database->prepare("SELECT * FROM tl_page WHERE pid=? AND alias_prefix=''")->execute($objPage->id);
		
		while( $objSubpages->next() )
		{
			$this->generateAlias($prefix, $objSubpages);
		}
	}
}

