<?php
/**
 * @version $Header$
 *
 * +----------------------------------------------------------------------+
 * | Copyright ( c ) 2004, bitweaver.org
 * +----------------------------------------------------------------------+
 * | All Rights Reserved. See below for details and a complete list of authors.
 * | Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See http://www.gnu.org/copyleft/lesser.html for details
 * |
 * | For comments, please use phpdocu.sourceforge.net documentation standards!!!
 * | -> see http://phpdocu.sourceforge.net/
 * +----------------------------------------------------------------------+
 * | Authors: xing <xing@synapse.plus.com>
 * +----------------------------------------------------------------------+
 *
 * Pigeonholes class
 *
 * @author   xing <xing@synapse.plus.com>
 * @version  $Revision$
 * @package  pigeonholes
 */

/**
 * required setup
 */
namespace Bitweaver\Pigeonholes;
use Bitweaver\BitBase;
use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Liberty\LibertyMime;
use Bitweaver\Liberty\LibertyStructure;
use Bitweaver\Users\RoleUser;

/**
 * Pigeonholes
 *
 * @package  pigeonholes
 */
class Pigeonholes extends LibertyMime {
	/**
	* initiate class
	* @param $pContentId content id of the pigeonhole - use either one of the ids.
	* @param $pStructureId structure id of the pigeonhole - use either one of the ids.
	* @param $pMembersList hash with optional values to tweak the getMemberList loading sql. Used keys are Order, Select, Join and Where.
	* @return void
	**/

	public $mMemberList;

	public function __construct( $pStructureId=null, $pContentId=null, $pMemberList=Null ) {
		parent::__construct();
		$this->registerContentType( PIGEONHOLES_CONTENT_TYPE_GUID, array(
			'content_type_guid' => PIGEONHOLES_CONTENT_TYPE_GUID,
			'content_name' => 'Pigeonhole',
			'handler_class' => 'Pigeonholes',
			'handler_package' => 'pigeonholes',
			'handler_file' => 'Pigeonholes.php',
			'maintainer_url' => 'http://www.bitweaver.org'
		) );
		$this->mContentId = $pContentId;
		$this->mStructureId = $pStructureId;
		$this->mContentTypeGuid = PIGEONHOLES_CONTENT_TYPE_GUID;

		// Permission setup
		$this->mViewContentPerm  = 'p_pigeonholes_view';
		$this->mUpdateContentPerm  = 'p_pigeonholes_update';
		$this->mAdminContentPerm = 'p_pigeonholes_update'; // use edit until we find the need for an admin permission

		// Allow specially constructed pigeonholes to mess with the
		// getMemberList SQL so that additional data can be added on.
		// This can be used in packages which want a special view on
		// a category.
		$this->mMemberList = $pMemberList;
	}

	/**
	* load the pigeonhole
	* @param $pExtras boolean - if set to true, pigeonhole content is added as well
	* @return bool true on success, false if it's not valid
	**/
	public function load( $pExtras=false, $pLoadAttachable=true ) {
		if( BitBase::verifyId( $this->mContentId ) || BitBase::verifyId( $this->mStructureId ) ) {
			global $gBitSystem;
			$lookupColumn = BitBase::verifyId( $this->mContentId ) ? 'lc.`content_id`' : 'ls.`structure_id`';
			$lookupId = BitBase::verifyId( $this->mContentId ) ? $this->mContentId : $this->mStructureId;
			$query = "SELECT pig.*, ls.`root_structure_id`, ls.`parent_id`, lc.`title`, lc.`data`,
				lc.`user_id`, lc.`content_type_guid`, lc.`format_guid`,
				uue.`login` AS modifier_user, uue.`real_name` AS modifier_real_name,
				uuc.`login` AS creator_user, uuc.`real_name` AS creator_real_name
				FROM `".BIT_DB_PREFIX."pigeonholes` pig
				INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = pig.`content_id` )
				LEFT JOIN `".BIT_DB_PREFIX."liberty_structures` ls ON ( ls.`structure_id` = pig.`structure_id` )
				LEFT JOIN `".BIT_DB_PREFIX."users_users` uue ON ( uue.`user_id` = lc.`modifier_user_id` )
				LEFT JOIN `".BIT_DB_PREFIX."users_users` uuc ON ( uuc.`user_id` = lc.`user_id` )
				WHERE $lookupColumn=?";
			$result = $this->mDb->query( $query, array( $lookupId ) );

			if( $result && $row = $result->fetchRow() ) {
				$this->mInfo = $row;
				$this->mContentId = $row['content_id'];
				$this->mStructureId = $row['structure_id'];
				$this->mInfo['user'] = $row['creator_user'];
				$this->mInfo['real_name'] = isset( $row['creator_real_name'] ) ? $row['creator_real_name'] : $row['creator_user'];
				$this->mInfo['display_name'] = RoleUser::getTitleFromHash( $this->mInfo );
				$this->mInfo['editor'] = isset( $row['modifier_real_name'] ) ? $row['modifier_real_name'] : $row['modifier_user'];
				$this->mInfo['display_link'] = $this->getDisplayLink();
				$this->mInfo['display_url'] = $this->getDisplayUrl();
				$this->parseData();
			}

			if( $pLoadAttachable ) {
				LibertyMime::load();
			}

			// if the content for the pigeonhole is requested, get it
			if( $pExtras ) {
				$this->mInfo['path'] = $this->getPigeonholePath();
				$this->mInfo['display_path'] = $this->getDisplayPath( $this->mInfo['path'] );
				$memberHash = array( 'max_records' => -1 );
				$this->mInfo['members'] = $this->getMemberList( $memberHash );
				$this->mInfo['members_count'] = count( $this->mInfo['members'] );
			}
		}
		return count( $this->mInfo );
	}

	/**
	* get all content inserted in a given pigeonhole. if no id is given, it gets all content for all pigeonholes
	* @param $pContentId content id of the pigeonhole
	* @return array of pigeonhole members with according title and content type guid
	**/
	public function getMemberList( &$pListHash ) {
		global $gBitUser, $gLibertySystem, $gBitSystem;
		$ret = false;
		LibertyContent::prepGetList( $pListHash );

		$select = $where = $join = '';
		$bindVars = [];
		if( BitBase::verifyId( $this->mContentId ?? 0 ) || BitBase::verifyId( $pListHash['content_id'] ?? 0 ) ) {
			$where = " WHERE pig.`content_id` = ? ";
			$bindVars[] = BitBase::verifyId( $pListHash['content_id'] ?? 0 ) ? $pListHash['content_id'] : $this->mContentId;
		}

		if( !empty( $pListHash['content_type_guid'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " lc.content_type_guid = ? ";
			$bindVars[] = $pListHash['content_type_guid'];
		}

		if( !empty( $pListHash['title'] ) && is_string( $pListHash['title'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " pig.`content_id` = lc2.`content_id` AND UPPER( lc2.`title` ) = ?";
			$join  .= ", `".BIT_DB_PREFIX."liberty_content` lc2";
			$bindVars[] = strtoupper( $pListHash['title'] );
		}

		// Do we have any special tweaks for the list?
		if( !empty( $this->mMemberList['Order'] ) ) {
			$order = "ORDER BY ".$this->mMemberList['Order'];
		} else {
			$order = "ORDER BY lc.`content_type_guid`, lc.`title` ASC";
		}

		if( !empty( $this->mMemberList['Select'] ) ) {
			$select .= $this->mMemberList['Select'];
		}

		if( !empty( $this->mMemberList['Join'] ) ) {
			$join .= $this->mMemberList['Join'];
		}

		if( !empty( $this->mMemberList['Where'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= $this->mMemberList['Where'];
		}


		$ret = [];
		$query = "
			SELECT pigm.*,
			lc.`content_id`, lc.`last_modified`, lc.`user_id`, lc.`title`, lc.`content_type_guid`, lc.`created`,
			lct.`content_name`, lcds.`data` AS `summary`,
			uu.`login`, uu.`real_name` $select
			FROM `".BIT_DB_PREFIX."pigeonhole_members` pigm
				INNER JOIN `".BIT_DB_PREFIX."pigeonholes` pig ON ( pig.`content_id` = pigm.`parent_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = pigm.`content_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_content_types` lct ON ( lc.`content_type_guid` = lct.`content_type_guid` )
				INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON ( uu.`user_id` = lc.`user_id` )
				LEFT OUTER JOIN `".BIT_DB_PREFIX."liberty_content_data` lcds ON ( lcds.`content_id` = lc.`content_id` AND lcds.`data_type` = 'summary' ) 
				LEFT OUTER JOIN `".BIT_DB_PREFIX."liberty_attachments` latt ON ( latt.`content_id` = lc.`content_id` AND latt.`is_primary` = 'y' ) 
				LEFT OUTER JOIN `".BIT_DB_PREFIX."liberty_files` lf ON ( lf.`file_id` = latt.`foreign_id` ) 
			$join $where $order";
		$result = $this->mDb->query( $query, $bindVars, BitBase::verifyId( $pListHash['max_records'] ?? 0 ) ? $pListHash['max_records'] : null, BitBase::verifyId( $pListHash['offset'] ?? 0 ) ? $pListHash['offset'] : null );
		$contentTypes = $gLibertySystem->mContentTypes;
		while( $aux = $result->fetchRow() ) {
			if( !empty( $contentTypes[$aux['content_type_guid']] ) ) {
				$type = &$contentTypes[$aux['content_type_guid']];
				if( empty( $type['content_object'] ) ) {
					// crate *one* object for each object *type* to  call virtual methods.
					if( $typeClass = $gLibertySystem->getContentClassName( $type['content_type_guid'] ) ) {
						if( $type['content_object'] = new $typeClass() ) {
							if( $type['content_object']->isViewable( $aux['content_id'] )) {
								$aux['display_url']   = $type['content_object']->getDisplayUrlFromHash( $aux );
								$aux['display_link']  = $type['content_object']->getDisplayLink( $aux );
								$aux['title']         = $type['content_object']->getTitleFromHash( $aux );
			// needs updating to bw3					$aux['thumbnail_url'] = liberty_fetch_thumbnails( []);
								$ret[] = $aux;
							}
						}
					}
				}
			}
		}

		$query_cant = "
			SELECT count(*)
			FROM `".BIT_DB_PREFIX."pigeonhole_members` pigm
				INNER JOIN `".BIT_DB_PREFIX."pigeonholes` pig ON ( pig.`content_id` = pigm.`parent_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = pigm.`content_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_content_types` lct ON ( lc.`content_type_guid` = lct.`content_type_guid` )
				INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON ( uu.`user_id` = lc.`user_id` )
				LEFT OUTER JOIN `".BIT_DB_PREFIX."liberty_content_data` lcds ON ( lcds.`content_id` = lc.`content_id` AND lcds.`data_type`='summary' ) 
			$join $where";
		$pListHash['cant'] = $this->mDb->getOne( $query_cant, $bindVars );

		LibertyContent::postGetList($pListHash);

		return !empty( $this->mErrors ) ? $this->mErrors : $ret;
	}

	/**
	* get all items that are not part of a pigeonhole yet
	* @return array of content not in any pigeonhole yet
	**/
	public function getAssignableContent( &$pListHash ) {
		global $gBitUser, $gLibertySystem, $gBitSystem;

		$where = '';
		$bindVars = [];
		LibertyContent::prepGetList( $pListHash );

		if( empty( $pListHash['include_members'] ) ) {
			$where .= "WHERE pigm.`content_id` IS null";
		}

		if( !empty( $pListHash['find'] ) && is_string( $pListHash['find'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " UPPER( lc.`title` ) LIKE ?";
			$bindVars[] = '%'.strtoupper( $pListHash['find'].'%');
		}

		if( !empty( $pListHash['content_type'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " lc.`content_type_guid`=?";
			$bindVars[] = $pListHash['content_type'];
		}

		$where .= empty( $where ) ? ' WHERE ' : ' AND ';
		$where .= " lc.`content_type_guid` != ? ";
		$bindVars[] = PIGEONHOLES_CONTENT_TYPE_GUID;

		if( !empty( $pListHash['sort_mode'] ) ) {
			$order = " ORDER BY ".$this->mDb->convertSortmode( $pListHash['sort_mode'] )." ";
		} else {
			$order = " ORDER BY lc.`content_type_guid`, lc.`title` ASC";
		}

		$query = "SELECT pigm.`parent_id`, lc.`content_id`, lc.`user_id`, lc.`title`, lc.`content_type_guid`, uu.`login`, uu.`real_name`
			FROM `".BIT_DB_PREFIX."liberty_content` lc
			LEFT JOIN `".BIT_DB_PREFIX."pigeonhole_members` pigm ON ( pigm.`content_id` = lc.`content_id` )
			LEFT JOIN `".BIT_DB_PREFIX."users_users` uu ON ( uu.`user_id` = lc.`user_id` )
			$where $order";
		$result = $this->mDb->query( $query, $bindVars, BitBase::verifyId( $pListHash['max_records'] ?? 0 ) ? $pListHash['max_records'] : null , $pListHash['offset']);

		$query = "SELECT COUNT(lc.`content_id`)
			FROM `".BIT_DB_PREFIX."liberty_content` lc
			LEFT JOIN `".BIT_DB_PREFIX."pigeonhole_members` pigm ON ( pigm.`content_id` = lc.`content_id` )
			LEFT JOIN `".BIT_DB_PREFIX."users_users` uu ON ( uu.`user_id` = lc.`user_id` )
			$where";
		$pListHash['cant'] = $this->mDb->getOne( $query, $bindVars);

		$contentTypes = $gLibertySystem->mContentTypes;
		while( $row = $result->fetchRow() ) {
			$i = $row['content_id'];
			$ret[$i] = $row;
			if( !empty( $contentTypes[$row['content_type_guid']] ) ) {
				$type = &$contentTypes[$row['content_type_guid']];
				if( empty( $type['content_object'] ) ) {
					// create *one* object for each object *type* to  call virtual methods.
					include_once $gBitSystem->mPackages[$type['handler_package']]['path'].$type['handler_file'];
					$type['content_object'] = new $type['handler_class']();
				}
				$ret[$i]['display_link'] = $type['content_object']->getDisplayLink( $row['title'], $row );
				$ret[$i]['title'] = $type['content_object']->getTitleFromHash( $row );
			}

			// generate a map of what items are assigned to what pigeonholes
			if( !empty( $pListHash['include_members'] ) && BitBase::verifyId( $row['parent_id'] ) ) {
				$map[$i][] = $row['parent_id'];
			}
		}

		// complete the output
		if( !empty( $pListHash['include_members'] ) && !empty( $ret ) ) {
			foreach( $ret as $i => $r ) {
				$ret[$i]['assigned'] = !empty( $map[$i] ) ? $map[$i] : null;
			}
		}

		LibertyContent::postGetList( $pListHash );
		return !empty( $ret ) ? $ret : null;
	}

	/**
	 * get an array of paths for all pigeonholes. used for pages where data can be inserted into pigeonholes
	 *
	 * @param int $pContentId content id of pigeonhole.
	 * @param int $pTruncate Setting this to a number will do some smart truncations depending on how many parents there are
	 *                           setting it to 60 will allow 30 chars for all parents combined and 30 for the actual title
	 * @return array|null if there is no pigeonhole
	 * @TODO We need to sort the returned values that successive pigoenholes are grouped together.
	 */
	public function getPigeonholesPathList( $pContentId=null, $pTruncate = false, $pShowAll = false ) {
		global $gBitSystem;
		$where = $join = '';

		if( $gBitSystem->isFeatureActive( 'pigeonholes_allow_forbid_insertion' ) && !$pShowAll ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= ' lcp.`pref_value` IS null OR lcp.`pref_value` != \'on\' ';
			$join .= ' LEFT JOIN `'.BIT_DB_PREFIX.'liberty_content_prefs` lcp ON (pig.`content_id` = lcp.`content_id` AND lcp.`pref_name` = \'no_insert\') ';
		}

		$query = "SELECT pig.`content_id`, pig.`structure_id`
			FROM `".BIT_DB_PREFIX."pigeonholes` pig
			INNER JOIN `".BIT_DB_PREFIX."liberty_structures` ls ON ( ls.`structure_id` = pig.`structure_id` )
			$join
			$where
			ORDER BY ls.`root_structure_id`, ls.`structure_id` ASC";
		$result = $this->mDb->query( $query );
		$pigeonholes = $result->getRows();
		foreach( $pigeonholes as $pigeonhole ) {
			$ret[$pigeonhole['content_id']] = $this->getPigeonholePath( $pigeonhole['structure_id'] );
		}

		if( !empty( $ret ) ) {
			if( $pTruncate ) {
				foreach( $ret as $cid => $path ) {
					// count here to minimise speed loss
					$count = count( $path );
					foreach( $path as $pos => $pig ) {
						// calculate limit at which category is truncated
						if( $count == 1 ) {
							$limit = $pTruncate;
						} elseif( $pos == $count - 1 ) {
							$limit = ceil( $pTruncate / 2 );
						} else {
							$limit = ceil( $pTruncate / 2 / $count );
						}
						$ret[$cid][$pos]['title'] = substr( $pig['title'], 0, $limit ).( ( strlen( $pig['title'] ) <= $limit ) ? '' : '...' );
					}
				}
			}

			// sort the pathlist to make the display nicer
			uasort( $ret, 'pigeonholes_pathlist_sorter' );

			if( BitBase::verifyId( $pContentId ) && $assigned = $this->getPigeonholesFromContentId( $pContentId ) ) {
				foreach( $assigned as $a ) {
					$ret[$a['content_id']][0]['selected'] = true;
				}
			}
		}

		return !empty( $ret ) ? $ret : null;
	}

	/**
	* get all pigeonholes where the contenent has been inserted
	* @param int $pContentId content id of item in question
	* @return array|null basic information about item requested
	**/
	public function getPigeonholesFromContentId( $pContentId ) {
		if( BitBase::verifyId( $pContentId ) ) {
			$query = "SELECT ls.*
				FROM `".BIT_DB_PREFIX."pigeonhole_members` pigm
				INNER JOIN `".BIT_DB_PREFIX."pigeonholes` pig ON ( pig.`content_id` = pigm.`parent_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_structures` ls ON ( pig.`structure_id` = ls.`structure_id` )
				WHERE pigm.`content_id`=?";
			$ret = $this->mDb->getAll( $query, [ $pContentId ] );
		}
		return !empty( $ret ) ? $ret : null;
	}

	/**
	* get the path of a pigeonhole
	* @param int|null $pStructureId structure id of pigeonhole, if no id is given, it gets the id from $this->mStructureId
	* @return array|null path in form of an array
	**/
	public function getPigeonholePath( $pStructureId = null ) {
		if( !BitBase::verifyId( $pStructureId ) ) {
			$pStructureId = $this->mStructureId;
		}

		if( BitBase::verifyId( $pStructureId ) ) {
			global $gStructure;
			// create new object if needed
			if( empty( $gStructure ) ) {
				$gStructure = new LibertyStructure();
			}
			// get the structure path
			$ret = $gStructure->getPath( $pStructureId );
		}
		return !empty( $ret ) ? $ret : null;
	}

	/**
	* Converts a structure path into valid html links
	* @param $pPath path given by getPigeonholePath()
	* @return string the link to display the page.
	*/
	public function getDisplayPath( $pPath ) {
		global $gBitSystem;
		$ret = '';
		if( !empty( $pPath ) && is_array( $pPath ) ) {
			foreach( $pPath as $node ) {
				$title = htmlspecialchars( $node['title'] );
				$ret .= ( BitBase::verifyId( $node['parent_id'] ) ? '&nbsp;&raquo;&nbsp;' : '' ).'<a title="'.$title.'" href="'.$this->getDisplayUrlFromHash( $node ).'">'.preg_replace('/ /','&nbsp;',$title).'</a>';
			}
		}

		return $ret;
	}

	/**
	 * get list of all pigeonholes
	 * @param $pListHash contains array of items used to limit search results
	 * @var string	[sort_mode] column and orientation by which search results are sorted
	 * @var string	[find] search for a pigeonhole title - case insensitive
	 * @var int		[max_records] maximum number of rows to return
	 * @var int		[offset] number of results data is offset by
	 * @var string	[title] pigeonhole name
	 * @var int		[parent_id] pigeonhole parent_id, optional
	 * @var int		[root_structure_id] only load the pigoenhole this root_structure_id is part of
	 * @var bool	[load_only_root] only load top most items
	 * @var int		[parent_content_id] all the sons of the pigeonhole parent content_id , optional
	 * @var bool	[load_also_root] if parent_content_id is set load also the father, optional
	 * @return array of pigeonholes in 'data' and count of pigeonholes in 'cant'
	**/
	public function getList( &$pListHash ) {
		global $gBitSystem, $gBitUser, $gBitDbType;
		LibertyContent::prepGetList( $pListHash );

		$ret = $bindVars = [];
		$where = $order = $join = $select = '';

		if( BitBase::verifyId( $pListHash['root_structure_id'] ?? 0 ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " ls.`root_structure_id`=? ";
			$bindVars[] = $pListHash['root_structure_id'];
		}

		if( !empty( $pListHash['load_only_root'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " ls.`structure_id`=ls.`root_structure_id` ";
		}

		if( !empty( $pListHash['find'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= " UPPER( lc.`title` ) LIKE ? ";
			$bindVars[] = '%'.strtoupper( $pListHash['find'] ).'%';
		}

		if( !empty( $pListHash['title'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .=  ' lc.`title` = ?';
			$bindVars[] = $pListHash['title'];
		}

		if( $gBitSystem->isFeatureActive( 'pigeonholes_allow_forbid_insertion' ) && !empty( $pListHash['insertable'] )) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= ' lcp.`pref_value` IS null OR lcp.`pref_value` != \'on\' ';
			$join .= ' LEFT JOIN `'.BIT_DB_PREFIX.'liberty_content_prefs` lcp ON (lc.`content_id` = lcp.`content_id` AND lcp.`pref_name` = \'no_insert\') ';
			$select .= ' , lcp.`pref_value` AS no_insert ';
		}

		if( isset( $pListHash['parent_id'] ) ) {
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= ' ls.`parent_id` = ? ';
			$bindVars[] = $pListHash['parent_id'];
		}

		if( !empty( $pListHash['parent_content_id'] ) ) {
			$join .= 'INNER JOIN `'.BIT_DB_PREFIX.'liberty_structures` lsf ON (ls.`parent_id` = lsf.`structure_id`';
			if ( !empty( $pListHash['load_also_root'] ) ) {
				$join .= ' OR ls.`structure_id`= lsf.`structure_id`';
			}
			$join .= ')';
			$where .= empty( $where ) ? ' WHERE ' : ' AND ';
			$where .= ' lsf.`content_id` = ? ';
			$bindVars[] = $pListHash['parent_content_id'];
		}

		if( !empty( $pListHash['sort_mode'] ) ) {
			$order .= " ORDER BY ".$this->mDb->convertSortmode( $pListHash['sort_mode'] )." ";
		} else {
			// default sort mode makes list look nice
			$order .= " ORDER BY ls.`root_structure_id`, ls.`structure_id` ASC";
		}

		// only use subselect for old crappy mysql
		if( $gBitDbType != 'mysql' ) {
			$subselect = ", (
				SELECT COUNT( pm.`content_id` )
				FROM `".BIT_DB_PREFIX."pigeonhole_members` pm
				WHERE pm.`parent_id`=pig.`content_id`
			) AS members_count";
		} else {
			$subselect = "";
		}

		$query = "SELECT pig.*, ls.`root_structure_id`, ls.`parent_id`, lc.`title`, lc.`data`, lc.`user_id`, lc.`content_type_guid`, lc.`format_guid`,
			uue.`login` AS modifier_user, uue.`real_name` AS modifier_real_name,
			uuc.`login` AS creator_user, uuc.`real_name` AS creator_real_name $select $subselect
			FROM `".BIT_DB_PREFIX."pigeonholes` pig
				INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = pig.`content_id` )
				LEFT JOIN `".BIT_DB_PREFIX."users_users` uue ON ( uue.`user_id` = lc.`modifier_user_id` )
				LEFT JOIN `".BIT_DB_PREFIX."users_users` uuc ON ( uuc.`user_id` = lc.`user_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_structures` ls ON ( ls.`structure_id` = pig.`structure_id` )
			$join $where $order";

		$result = $this->mDb->query( $query, $bindVars, $pListHash['max_records'], $pListHash['offset'] );

		while( $aux = $result->fetchRow() ) {
			//$content_ids[]        = $aux['content_id'];
			$aux['user']         = $aux['creator_user'];
			$aux['real_name']    = isset( $aux['creator_real_name'] ) ? $aux['creator_real_name'] : $aux['creator_user'];
			$aux['display_name'] = RoleUser::getDisplayNameFromHash( $aux );
			$aux['editor']       = isset( $aux['modifier_real_name'] ) ? $aux['modifier_real_name'] : $aux['modifier_user'];
			$aux['display_link'] = Pigeonholes::getDisplayLink( $aux['title'], $aux );
			// get member count for mysql - haha
			if( $gBitDbType == 'mysql' ) {
				$aux['members_count'] = $this->mDb->getOne( "SELECT COUNT( pm.`content_id` ) FROM `".BIT_DB_PREFIX."pigeonhole_members` pm WHERE pm.`parent_id`=?", array( $aux['content_id'] ));
			}

			if( !empty( $pListHash['parse_data'] ) && !empty( $aux['data'] )) {
				$aux['parsed_data'] = $this->parseData( $aux['data'], $aux['format_guid'] );
			}

			if( !empty( $pListHash['force_extras'] ) || ( !empty( $pListHash['load_extras'] ) && $aux['structure_id'] == @$pListHash['structure_id'] ) ) {
				$aux['path']         = $this->getPigeonholePath( $aux['structure_id'] );
				$aux['display_path'] = Pigeonholes::getDisplayPath( $aux['path'] );
				// Move all the members data into the right place
				$memberListHash = [
					'content_id'        => $aux['content_id'],
					'content_type_guid' => !empty( $pListHash['content_type_guid'] )   ? $pListHash['content_type_guid']   : null,
					'max_records'       => !empty( $pListHash['members_max_records'] ) ? $pListHash['members_max_records'] : null,
					'list_page'         => !empty( $pListHash['members_list_page'] )   ? $pListHash['members_list_page']   : null,
					'sort_mode'         => !empty( $pListHash['members_sort_mode'] )   ? $pListHash['members_sort_mode']   : null,
					'find'              => !empty( $pListHash['members_find'] )        ? $pListHash['members_find']        : null,
				];
				$aux['members']  = $this->getMemberList( $memberListHash );
				$aux['listInfo'] = $memberListHash['listInfo'];

				//$aux['members_count'] = count( $aux['members'] );
				if( $gBitSystem->getConfig( 'pigeonholes_list_style' ) == 'table' ) {
					$this->alphabetiseMembers( $aux['members'] );
				}
			}

			$ret[$aux['structure_id']] = $aux;
		}

		$query = "SELECT COUNT( lc.`title` )
			FROM `".BIT_DB_PREFIX."pigeonholes` pig
				INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = pig.`content_id` )
				LEFT JOIN `".BIT_DB_PREFIX."users_users` uue ON ( uue.`user_id` = lc.`modifier_user_id` )
				LEFT JOIN `".BIT_DB_PREFIX."users_users` uuc ON ( uuc.`user_id` = lc.`user_id` )
				INNER JOIN `".BIT_DB_PREFIX."liberty_structures` ls ON ( ls.`structure_id` = pig.`structure_id` )
			$join $where";
		$pListHash['cant'] = $this->mDb->getOne( $query, $bindVars );

		LibertyContent::postGetList( $pListHash );
		return $ret;
	}

	/**
	* Check permissions of all nodes that lead to this
	* @return bool a nicely grouped set of pigeonhole members in a set of columns and starting letters.
	**/
	public function checkPathPermissions( $pPath ) {
		global $gBitUser, $gBitSystem;
		if( !empty( $pPath ) && is_array( $pPath )) {
			foreach( $pPath as $path ) {
				$contentIds[] = $path['content_id'];
			}
			if( !empty( $contentIds ) ) {
				$query = "SELECT `pref_name`, `pref_value` FROM `".BIT_DB_PREFIX."liberty_content_prefs` WHERE `content_id` IN( ".preg_replace( "/,$/", "", str_repeat( "?,", count( $contentIds ) ) )." ) ";
				$result = $this->mDb->query( $query, $contentIds );
				while( $aux = $result->fetchRow() ) {
					$aux['pref_name'] = $aux['pref_value'];
					if( ( !empty( $group_id ) && !$gBitUser->isInGroup( $group_id ) ) || ( !empty( $permission ) && !$gBitUser->hasPermission( $permission ) ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	* Alphabetise all member items
	* @return void array a nicely grouped set of pigeonhole members in a set of columns and starting letters.
	**/
	public function alphabetiseMembers( &$pMememberHash ) {
		global $gBitSystem;
		if( !empty( $pMememberHash ) ) {
			usort( $pMememberHash, "pigeonholes_alphabetiser" );
			$per_column = ceil( count( $pMememberHash ) / $gBitSystem->getConfig( 'pigeonholes_display_columns', 3 ) );
			$i = 1;
			$j = 1;
			foreach( $pMememberHash as $member ) {
				$column = ( $i++ % $per_column == 0 ) ? $j++ : $j;
				$index = strtoupper( substr( $member['title'], 0, 1 ) );
				// check if the previous column was using the same letter as we want to use in the new column
				if(  !empty( $ret[$column - 1][$index] ) || !empty( $ret[$column - 1]["&hellip;".$index] ) ) {
					$index = "&hellip;".$index;
				}
				$ret[$column][$index][] = $member;
			}
			$pMememberHash = $ret;
			unset( $ret );
		}
	}

	/**
	 * Store pigeonhole data
	 * @param $pParamHash contains all data to store the pigeonholes
	 * @var string [title] title of the new pigeonhole
	 * @var string [edit] description of the pigeonhole
	 * @var array [members] array of content_ids that are associated with this pigeonhole
	 * @var int [root_structure_id] if this is set, it will add the pigeonhole to this structure. if it's not set, a new structure / top level pigeonhole is created
	 * @var int [parent_id] set the structure_id that will server as the parent in the structure
	 * @return bool true on success, false if store could not occur. If false, $this->mErrors will have reason why
	**/
	public function store( array &$pParamHash): bool {
		$this->mDb->StartTrans();
		if( $this->verify( $pParamHash ) && LibertyMime::store( $pParamHash ) ) {
			$table = BIT_DB_PREFIX."pigeonholes";

			// this really confusing, strange order way of saving items is due to strange behaviour of GenID
			// probably has to do with not null default nextval('public.liberty_structures_id_seq'::text)
			if( !empty( $pParamHash['update'] ) ) {
				if( !empty( $pParamHash['pigeonhole_store'] ) ) {
					$result = $this->mDb->associateUpdate( $table, $pParamHash['pigeonhole_store'], array("content_id" => $this->mContentId ) );
				}
				$pParamHash['structure_location_id'] = $this->mStructureId;
			} else {
				// update the pigeonhole_store and structure_store content_id with the one from LibertyMime::store()
				$pParamHash['structure_store']['content_id'] = $pParamHash['content_id'];
				$pParamHash['pigeonhole_store']['content_id'] = $pParamHash['content_id'];

				// we need to store the new structure node now
				global $gStructure;
				// create new object if needed
				if( empty( $gStructure ) ) {
					$gStructure = new LibertyStructure();
				}
				$pParamHash['structure_location_id'] = $gStructure->storeNode( $pParamHash['structure_store'] );

				// get the corrent structure_id
				// structure_id has to be done like this since it's screwed up in the schema
				$pParamHash['pigeonhole_store']['structure_id'] =  $this->mDb->getOne( "SELECT MAX( `structure_id` ) FROM `".BIT_DB_PREFIX."liberty_structures`" );
				$result = $this->mDb->associateInsert( $table, $pParamHash['pigeonhole_store'] );
			}

			// store content items
			if( !empty( $pParamHash['pigeonhole_members_store'] ) ) {
				// remove items first
				$this->expungePigeonholeMember( array( 'parent_id' => $this->mContentId ) );
				if( !$this->insertPigeonholeMember( $pParamHash['pigeonhole_members_store'] ) ) {
					$this->mErrors['store'] = 'The content could not be inserted into the respective categories.';
				}
			}

			$this->mDb->CompleteTrans();
			$this->load();
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	* verify, clean up and prepare data to be stored
	* @param $pParamHash all information that is being stored. will update $pParamHash by reference with fixed array of itmes
	* @return bool true on success, false if store could not occur. If false, $this->mErrors will have reason why
	* @access private
	**/
	public function verify( array &$pParamHash ): bool {
		// make sure we're all loaded up if everything is valid
		if( $this->isValid() && empty( $this->mInfo ) ) {
			$this->load( true );
		}

		// It is possible a derived class set this to something different
		if( empty( $pParamHash['content_type_guid'] ) ) {
			$pParamHash['content_type_guid'] = $this->mContentTypeGuid;
		}

		if( BitBase::verifyId( $this->mContentId ) ) {
			$pParamHash['content_id'] = $this->mContentId;
			$pParamHash['update'] = true;
		}

		// content store
		// check for name issues, first truncate length if too long
		if( !empty( $pParamHash['title'] ) )  {
			if( !BitBase::verifyId( $this->mContentId ) ) {
				if( empty( $pParamHash['title'] ) ) {
					$this->mErrors['title'] = 'You must enter a name for this category.';
				} else {
					$pParamHash['content_store']['title'] = substr( $pParamHash['title'], 0, 160 );
				}
			} else {
				$pParamHash['content_store']['title'] = isset( $pParamHash['title'] ) ? substr( $pParamHash['title'], 0, 160 ) : $this->mInfo['title'];
			}
		} elseif( empty( $pParamHash['title'] ) ) {
			// no name specified
			$this->mErrors['title'] = 'You must enter a name for this category.';
		}

		// sort out the description
		if( $this->isValid() && !empty( $this->mInfo['data'] ) && empty( $pParamHash['edit'] ) ) {
			$pParamHash['edit'] = '';
		} elseif( empty( $pParamHash['edit'] ) ) {
			unset( $pParamHash['edit'] );
		}

		// pigeonhole member store
		// work out what to do with the content of the pigeonhole
		if( $this->isValid() && !empty( $this->mInfo['members'] ) && empty( $pParamHash['members'] ) ) {
			$pParamHash['pigeonhole_members_store']['members'] = '';
		} elseif( empty( $pParamHash['members'] ) ) {
			unset( $pParamHash['members'] );
		} else {
			$i = 1;
			foreach( $pParamHash['members'] as $c_id ) {
				$pParamHash['pigeonhole_members_store'][$i]['content_id'] = $c_id;
				$i++;
			}
		}

		// individual pigeonhole preference store
		global $gBitSystem;
		if( $gBitSystem->isFeatureActive('pigeonholes_allow_forbid_insertion') && empty( $pParamHash['prefs']['no_insert'] ) ) {
			$pParamHash['prefs']['no_insert'] = '0';
		}
		$pParamHash['preferences_store'] = !empty( $pParamHash['prefs'] ) ? $pParamHash['prefs'] : null;

		// structure store
		if( BitBase::verifyId( $pParamHash['root_structure_id'] ?? 0 ) ) {
			$pParamHash['structure_store']['root_structure_id'] = $pParamHash['root_structure_id'];
		} else {
			$pParamHash['structure_store']['root_structure_id'] = null;
		}

		if( BitBase::verifyId( $pParamHash['parent_id'] ?? 0 ) ) {
			$pParamHash['structure_store']['parent_id'] = $pParamHash['parent_id'];
		} else {
			$pParamHash['structure_store']['parent_id'] = null;
		}

		return count( $this->mErrors ) == 0;
	}

	/**
	* Store pigeonhole member
	* @param $pParamHash an array of content to be stored.
	* @param $pParamHash[parent_id] id of pigeonhole it belongs to, default is $this->mContentId
	* @param $pParamHash[content_id] content_id of the item to be stored
	* @return bool true on success, false if store could not occur. If false, $this->mErrors will have reason why
	**/
	public function insertPigeonholeMember( &$pParamHash ) {
		if( $this->verifyPigeonholeMember( $pParamHash ) ) {
			foreach( $pParamHash['member_store'] as $item ) {
				$result = $this->mDb->associateInsert( BIT_DB_PREFIX."pigeonhole_members", $item );
			}
		} else {
			error_log( "Error inserting pigeonhole: " . vc($this->mErrors));
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	* verify, clean up and prepare data to be stored
	* @param $pParamHash all information that is being stored. will update $pParamHash by reference with fixed array of itmes
	* @return bool true on success, false if store could not occur. If false, $this->mErrors will have reason why
	* @access private
	**/
	public function verifyPigeonholeMember( &$pParamHash ) {
		$this->mDb->StartTrans();
		foreach( $pParamHash as $key => $item ) {
			if( isset( $item['parent_id'] ) && BitBase::verifyId( $item['parent_id'] ) ) {
				$tmp['member_store'][$key]['parent_id'] = $item['parent_id'];
			} elseif( BitBase::verifyId( $this->mContentId ) ) {
				$tmp['member_store'][$key]['parent_id'] = $this->mContentId;
				$pParamHash[$key]['parent_id'] = $this->mContentId;
			} else {
				$this->mErrors['store_members'] = tra( 'The content could not be inserted because the parent_id was missing.' );
			}

			if( isset( $item['content_id'] ) && BitBase::verifyId( $item['content_id'] ) ) {
				$tmp['member_store'][$key]['content_id'] = $item['content_id'];
			} else {
				$this->mErrors['store_members'] = 'The content id is not valid.';
			}
		}

		$this->mDb->CompleteTrans();
		$pParamHash = $tmp;
		return count( $this->mErrors ) == 0;
	}

	/**
	* Expunge pigeonhole member
	* @param $pParamHash['parent_id'] parent_id of content to be deleted
	* @param $pParamHash['member_id'] content_id of content to be deleted
	* @param $pParamHash['deletables'] array of content_ids to check against when deleting. makes sure that only members of a given structure are removed
	*	Note if only one of the 2 ids is given, all items with that id will be removed. if both are given, only that one particular entry is removed
	* @return bool true on success, false if store could not occur. If false, $this->mErrors will have reason why
	**/
	public function expungePigeonholeMember( $pParamHash ) {
	    if( BitBase::verifyId( $pParamHash['parent_id'] ) || BitBase::verifyId( $pParamHash['member_id'] ?? 0 ) ) {
			$where = '';
			$bindVars = [];

			if( BitBase::verifyId( $pParamHash['parent_id'] ?? 0 ) ) {
				$where .= " WHERE `parent_id`=? ";
				$bindVars[] = $pParamHash['parent_id'];
			}

			if( BitBase::verifyId( $pParamHash['member_id'] ?? 0 ) ) {
				$where .= ( empty( $where ) ? " WHERE " : " AND " )." `content_id`=? ";
				$bindVars[] = $pParamHash['member_id'];
			}

			if( !empty( $pParamHash['deletables'] ) && is_array( $pParamHash['deletables'] ) ) {
				// only delete member data when it's part of the deletable structure
				$where .= ( empty( $where ) ? " WHERE " : " AND " )." `parent_id` IN( ".preg_replace( "/,$/", "", str_repeat( "?,", count( $pParamHash['deletables'] ) ) )." ) ";
				$bindVars = array_merge( $bindVars, $pParamHash['deletables'] );
			}

			// now we're ready to remove the actual members
			$query = "DELETE FROM `".BIT_DB_PREFIX."pigeonhole_members` $where";
			$result = $this->mDb->query( $query, $bindVars );
		} else {
			$this->mErrors['members_store'] = 'The category member(s) could not be removed.';
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	* Expunge currently loaded pigeonhole
	* @return void
	**/
	public function expunge(): bool {
		// if we have a custom structure id we want to remove, load it
		if( BitBase::verifyId( $this->mStructureId ) ) {
			$this->load();
		}

		if( $this->isValid() ) {
			$this->mDb->StartTrans();
			// get all items that are part of the sub tree
			$struct = new LibertyStructure();

			// include the current structure id as well
			$structureIds[] = $this->mStructureId;
			$tree = $struct->getSubTree( $this->mStructureId );
			foreach( $tree as $node ) {
				$structureIds[] = $node['structure_id'];
			}

			$structureIds = array_unique( $structureIds );
			$where = '';
			foreach( $structureIds as $structureId ) {
				$where .= ( empty( $where ) ? " WHERE " : " OR ")."`structure_id`=?";
			}
			$result = $this->mDb->query( "SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_structures` $where", $structureIds );
			$contentIds = $result->getRows();

			foreach( $contentIds as $id ) {
				// now we have the content ids - let the nuking begin
				$query = "DELETE FROM `".BIT_DB_PREFIX."pigeonholes` WHERE `content_id` = ?";
				$result = $this->mDb->query( $query, array( $id['content_id'] ) );
				$query = "DELETE FROM `".BIT_DB_PREFIX."pigeonhole_members` WHERE `parent_id` = ?";
				$result = $this->mDb->query( $query, array( $id['content_id'] ) );

				// remove all entries from content tables
				$this->mContentId = $id['content_id'];
				if( LibertyMime::expunge() ) {
					$ret = true;
					$this->mDb->CompleteTrans();
				} else {
					$this->mDb->RollbackTrans();
				}
			}

			// finally nuke the structure in liberty_structures
			$struct->removeStructureNode( $this->mStructureId, false );
		}
		return true;
	}

	/**
	* Generates the URL to this pigeonhole
	* @param array $pParamHash is the pigeonhole id we want to see
	* @return string the link to display the page.
	*/
	public static function getDisplayUrlFromHash( &$pParamHash ) {
		global $gBitSystem;
		$ret = null;

		if( BitBase::verifyId( $pParamHash['content_id'] ) ) {
			$rewrite_tag = $gBitSystem->isFeatureActive( 'pretty_urls_extended' ) ? 'view/' : '';
			$ret = $gBitSystem->isFeatureActive( 'pretty_urls' ) || $gBitSystem->isFeatureActive( 'pretty_urls_extended' )
				? PIGEONHOLES_PKG_URL.$rewrite_tag.$pParamHash['content_id']
				: PIGEONHOLES_PKG_URL.'view.php?content_id='.$pParamHash['content_id'];
		}

		return $ret;
	}

	/**
	* Returns HTML link to display a pigeonhole
	* @param string $pLinkText is the pigeonhole we want to see
	* @param array $pMixed content id of the pigeonhole in question
	* @param $pAnchor
	* @return string the link to display the page.
	*/
	public function getDisplayLink( $pLinkText = null, $pMixed = null, $pAnchor = null ) {
		global $gBitSystem;
		if( empty( $pLinkText ) && !empty( $this ) ) {
			$ppLinkText = $this->getTitle();
		}

		if( empty( $pMixed ) && !empty( $this ) ) {
			$pMixed = $this->mInfo;
		}

		$ret = $pLinkText;
		if( !empty( $pLinkText ) && !empty( $pMixed ) ) {
			if( $gBitSystem->isPackageActive( 'pigeonholes' ) ) {
				$ret = '<a title="'.htmlspecialchars( $pLinkText ).'" href="'.Pigeonholes::getDisplayUrlFromHash( $pMixed ).'">'.htmlspecialchars( $pLinkText ).'</a>';
			}
		}

		return $ret;
	}

	/**
	 * Get all child pigeonholes starting from any parent
	 * 
	 * @param array $pContentId of the pigoenhole
	 * @param array $pStructureId of the pigeonhole
	 * @return array of child pigeonholes on success, empty array on failure
	 */
	public function getSubPigeonholes( $pContentId = null, $pStructureId = null ) {
		global $gStructure;
		$ret = [];

		if( empty( $gStructure )) {
			$struct = new LibertyStructure();
		} else {
			$struct = &$gStructure;
		}

		if( BitBase::verifyId( $pContentId ) && !BitBase::verifyId( $pStructureId )) {
			$pigeon = $struct->getNode( null, $pContentId );
			$pStructureId = $pigeon['structure_id'];
		}

		if( BitBase::verifyId( $pStructureId )) {
			$tree = $struct->getSubTree( $pStructureId );

			// weed out duplicates
			foreach( $tree as $pigeon ) {
				if( !in_array( $pigeon['content_id'], array_keys( $ret ))) {
					$ret[$pigeon['content_id']] = $pigeon;
				}
			}
		}

		return $ret;
	}
}

function pigeonholes_alphabetiser( $a, $b ) {
	return strcasecmp( $a["title"], $b["title"] );
}

function pigeonholes_pathlist_sorter( $aa, $ab ) {
	foreach( $aa as $key => $a ) {
		if( !empty( $ab[$key] ) ) {
			if( $a['pos'] < $ab[$key]['pos'] ) {
				return -1;
			} elseif( $a['pos'] > $ab[$key]['pos'] ) {
				return 1;
			}
		} else {
			return 1;
		}
	}
}


// ============= SERVICE FUNCTIONS =============

/**
 * pigeonholes_content_display 
 * 
 * @param array $pObject 
 * @access public
 * @return bool true on success, false on failure - mErrors will contain reason for failure
 */
function pigeonholes_content_display( &$pObject ) {
	global $gBitSystem, $gBitSmarty, $gBitUser, $gBitThemes;
	$pigeonholes = new Pigeonholes();

	// first we need to check permissions
	if( $gBitSystem->isFeatureActive( 'pigeonhole_permissions' ) || $gBitSystem->isFeatureActive( 'pigeonholes_groups' )) {
		if( $pigeons = $pigeonholes->getPigeonholesFromContentId( $pObject->mContentId )) {
			foreach( $pigeons as $pigeon ) {
				// we will loop through here until we get one pigeonhole that allows access
				if( empty( $access_granted )) {
					$access_granted =  $pigeonholes->checkPathPermissions( $pigeonholes->getPigeonholePath( $pigeon['structure_id'] ) ) ? true : false;
				}
			}
		}

		// we need to check all pigeonholes in the path, load the prefs and work out if the user is allowed to view the page
		if( isset( $access_granted ) && $access_granted === false ) {
			$msg = tra( "This content is part of a category to which you have no access to. Please log in or request the appropriate permission from the site administrator." );
			$gBitSystem->fatalPermission( null, $msg );
		}
	}

	if( $gBitSystem->isFeatureActive( 'pigeonholes_display_members' ) || $gBitSystem->isFeatureActive( 'pigeonholes_display_path' )) {
		if( $gBitUser->hasPermission( 'p_pigeonholes_view' )) {
			if( $pigeons = $pigeonholes->getPigeonholesFromContentId( $pObject->mContentId )) {
				foreach( $pigeons as $key => $pigeon ) {
					$pigeonholes->mContentId = $pigeon['content_id'];
					$pigeonholes->load( true, false );
					$pigeonData[] = $pigeonholes->mInfo;

					// set the theme chosen for this page - virtually random if page is part of multiple themes
					if( $gBitSystem->isFeatureActive( 'pigeonholes_themes' )) {
						// loadPreferences is called by getPreference if needed
						$gBitThemes->setStyle( $pigeonholes->getPreference( 'style' ));
					}
				}
				$gBitSmarty->assign( 'pigeonData', !empty( $pigeonData ) ? $pigeonData : false );
			}
		}
	}
}

/**
 * pigeonholes_content_edit 
 * 
 * @param array $pObject 
 * @access public
 * @return bool true on success, false on failure - mErrors will contain reason for failure
 */
function pigeonholes_content_edit( $pObject=null ) {
	global $gBitSmarty, $gBitUser, $gBitSystem;
	$pigeonPathList = [];

	if( is_object($pObject) && isset($pObject->mContentTypeGuid) &&
		!$gBitSystem->isFeatureActive('pigeonhole_no_'.$pObject->mContentTypeGuid) &&
		$gBitUser->hasPermission( 'p_pigeonholes_insert_member' ) ) {
		$pigeonholes = new Pigeonholes();

		$gBitSmarty->assign('editPigeonholesEnabled', true);

		// get pigeonholes path list
		if( $pigeonPathList = $pigeonholes->getPigeonholesPathList(!empty( $pObject->mContentId ) ? $pObject->mContentId : null, $gBitSystem->isFeatureActive( 'pigeonholes_use_jstab' ) ? false : 100 )) {
			$gBitSmarty->assign( 'pigeonPathList', $pigeonPathList );
		}
	} else {
		$gBitSmarty->assign('editPigeonholesEnabled', false);
	}
}

/**
 * pigeonholes_content_expunge 
 * 
 * @param array $pObject 
 * @access public
 * @return bool true on success, false on failure - mErrors will contain reason for failure
 */
function pigeonholes_content_expunge( $pObject=null ) {
	$pigeonholes = new Pigeonholes();
	$pigeonholes->expungePigeonholeMember( [ 'member_id' => $pObject->mContentId ] );
}

/**
 * pigeonholes_content_preview 
 * 
 * @return void
 */
function pigeonholes_content_preview( $pObject=null, $pParamHash ) {
	global $gBitSmarty, $gBitUser, $gBitSystem;
	$pigeonPathList = [];

	if( is_object($pObject) && isset($pObject->mContentTypeGuid) &&
		!$gBitSystem->isFeatureActive('pigeonhole_no_'.$pObject->mContentTypeGuid) &&
		$gBitUser->hasPermission( 'p_pigeonholes_insert_member' ) ) {
		$pigeonholes = new Pigeonholes();

		// get pigeonholes path list
		if( $pigeonPathList = $pigeonholes->getPigeonholesPathList() ) {
			foreach( $pigeonPathList as $key => $path ) {
				$pigeonPathList[$key][0]['selected'] = !empty( $pParamHash['pigeonholes']['pigeonhole'] ) && in_array( $key, $pParamHash['pigeonholes']['pigeonhole'] ) ? true : false;
			}
			$gBitSmarty->assign( 'pigeonPathList', $pigeonPathList );
		}
	}
}

/**
 * pigeonholes_content_store 
 * 
 * @param array $pObject 
 * @param array $pParamHash 
 * @access public
 * @return void
 */
function pigeonholes_content_store( $pObject, $pParamHash ) {
	global $gBitSmarty, $gBitUser, $gBitSystem;
	if( is_object($pObject) && isset($pObject->mContentTypeGuid) &&
		!$gBitSystem->isFeatureActive('pigeonhole_no_'.$pObject->mContentTypeGuid) &&
		$gBitUser->hasPermission( 'p_pigeonholes_insert_member' ) ) {

		if( is_object( $pObject ) && empty( $pParamHash['content_id'] ) ) {
			$pParamHash['content_id'] = $pObject->mContentId;
		}

		if( !empty( $pParamHash['content_id'] ) ) {

			$pigeonholes = new Pigeonholes();
			$pigeonPathList = $pigeonholes->getPigeonholesPathList( $pParamHash['content_id'] );

			// here we need to work out if we need to save at all
			// get all originally selected items
			$selectedItem = [];
			if( !empty( $pigeonPathList ) ) {
				foreach( $pigeonPathList as $path ) {
					if( !empty( $path[0]['selected'] ) ) {
						$pathItem = array_pop( $path );
						$selectedItem[] = $pathItem['content_id'];
					}
				}
			}

			// quick and dirty check to start off with
			if( empty( $pParamHash['pigeonholes'] ) || count( $pParamHash['pigeonholes']['pigeonhole'] ) != count( $selectedItem ) ) {
				$modified = true;
			} else {
				// more thorough check
				foreach( $selectedItem as $item ) {
					if( !in_array( $item, $pParamHash['pigeonholes']['pigeonhole'] ) ) {
						$modified = true;
					}
				}
			}

			if( !empty( $modified ) ) {
				// first remove all entries with this content_id
				if( $pigeonholes->expungePigeonholeMember( [ 'member_id' => $pParamHash['content_id'] ] ) && !empty( $pParamHash['pigeonholes'] ) )  {
					// insert the content into the desired pigeonholes
					foreach( $pParamHash['pigeonholes']['pigeonhole'] as $p_id ) {
						$memberHash[] = [							
							'parent_id' => $p_id,
							'content_id' => $pParamHash['content_id']
						];
					}

					if( !$pigeonholes->insertPigeonholeMember( $memberHash ) ) {
						$gBitSmarty->assign( 'msg', tra( "There was a problem inserting the content into the pigeonholes." ) );
						$gBitSystem->display( 'error.tpl' , null, [ 'display_mode' => 'display' ] );
						die;
					}
				}
			}
		}
	}
}

/**
 * When the list function is called and the template, a filter option will appear based on categories
 * 
 * @param array $pObject Current object
 * @param array $pParamHash Parameter hash - only works if $pParamHash[pigeonholes][filter] is passed back to the list sql funciton
 * @access public
 * @return void
 */
function pigeonholes_content_list( &$pObject, $pParamHash = null ) {
	global $gBitSystem, $gBitSmarty;
	if( $gBitSystem->isFeatureActive( 'pigeonholes_list_filter' )) {
		$pigeonholes = new Pigeonholes();
		$listHash = array(
			'sort_mode' => array(
				'root_structure_id_asc', 'title_asc'
			),
			'insertable' => true,
		);
		$pigeonList = $pigeonholes->getList( $listHash );
		$list = [];
		foreach( $pigeonList as $pigeon ) {
			$list[$pigeon['content_id']] = $pigeon['display_link'];
		}
		$gBitSmarty->assign( 'pigeonList', $list );
	}
}

/**
 * filter the search with pigeonholes
 * @param $pParamHash['pigeonholes']['filter'] - a pigeonhole or an array of pigeonhole content_id
 **/
function pigeonholes_content_list_sql( &$pObject, $pParamHash = null ) {
	global $gBitSystem;
	$ret = [];

	if( !empty( $pParamHash['pigeonholes']['no_filter'] )) {
		$pParamHash['pigeonholes']['filter'] = [];
	} else {
		if( !empty( $pParamHash['pigeonholes']['filter'] )) {
			$pParamHash['liberty_categories'] = $pParamHash['pigeonholes']['filter'];
		}

		if( !empty( $pParamHash['liberty_categories'] )) {
			if( !is_array( $pParamHash['liberty_categories'] )) {
				$pParamHash['liberty_categories'] = array( $pParamHash['liberty_categories'] );
			}

			// if we want to allow items in subcategories, we get those and include them in the query
			if( !empty( $pParamHash['pigeonholes']['sub_holes'] )) {
				$pigeonholes = new Pigeonholes();
				$contentIds = [];
				foreach( $pParamHash['liberty_categories'] as $pigeonhole ) {
					$pigeons = $pigeonholes->getSubPigeonholes( $pigeonhole );
					$contentIds = array_merge( $contentIds, array_keys( $pigeons ));
				}
				$contentIds = array_unique( array_merge( $pParamHash['liberty_categories'], $contentIds ));
			} else {
				$contentIds = $pParamHash['liberty_categories'];
			}

			$ret['join_sql']  = "INNER JOIN `".BIT_DB_PREFIX."pigeonhole_members` pm ON (lc.`content_id`=pm.`content_id`)";
			$ret['where_sql'] = 'AND pm.`parent_id` IN ('.implode( ',', array_fill( 0, count( $contentIds ), '?' )).')';
			$ret['bind_vars'] = $pParamHash['pigeonholes']['filter'] = $contentIds;
		}

		if( !empty( $pParamHash['pigeonholes']['root_filter'] )) {
			$pParamHash['liberty_root_categories'] = $pParamHash['pigeonholes']['root_filter'];
		}

		if( !empty( $pParamHash['liberty_root_categories'] )) {
			if( !is_array( $pParamHash['liberty_root_categories'] )) {
				$pParamHash['liberty_root_categories'] = array( $pParamHash['liberty_root_categories'] );
			}

			// if we want to allow items in subcategories, we get those and include them in the query
			if( !empty( $pParamHash['pigeonholes']['root_sub_holes'] )) {
				$pigeonholes = new Pigeonholes();
				$contentIds = [];
				foreach( $pParamHash['liberty_root_categories'] as $pigeonhole ) {
					$pigeons = $pigeonholes->getSubPigeonholes( $pigeonhole );
					$contentIds = array_merge( $contentIds, array_keys( $pigeons ));
				}
				$contentIds = array_unique( array_merge( $pParamHash['liberty_root_categories'], $contentIds ));
			} else {
				$contentIds = $pParamHash['liberty_root_categories'];
			}

			$ret['join_sql']  = "INNER JOIN `".BIT_DB_PREFIX."pigeonhole_members` rpm ON (rlc.`content_id`=rpm.`content_id`)";
			$ret['where_sql'] = 'AND rpm.`parent_id` IN ('.implode( ',', array_fill( 0, count( $contentIds ), '?' )).')';
			$ret['bind_vars'] = $pParamHash['pigeonholes']['filter'] = $contentIds;
		}
	}

	return $ret;
}