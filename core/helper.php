<?php
/**
 *
 * @package       phpBB Extension - S3
 * @copyright (c) 2017 Austin Maddox
 * @license       http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace AustinMaddox\s3\core;



class helper
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;
	
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
	)
	{
		$this->db = $db;
	}
	public function get_physical_filename($attach_id)
	{
		$sql = 'SELECT physical_filename
			FROM ' . ATTACHMENTS_TABLE . "
			WHERE attach_id = $attach_id";
		$result = $this->db->sql_query($sql);
		$attachment = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		
		return $attachment['physical_filename'];
	}
}
