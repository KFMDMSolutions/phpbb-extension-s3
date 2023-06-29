<?php
/**
 *
 * @package       phpBB Extension - S3
 * @copyright (c) 2017 Austin Maddox
 * @license       http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace AustinMaddox\s3\controller;


use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;
use phpbb\exception\http_exception;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\user;
use Symfony\Component\HttpFoundation\Request as symfony_request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;


/**
 * Event listener
 */
 abstract class attachment_category
{
	/** @var int None category */
	public const NONE = 0;

	/** @var int Inline images */
	public const IMAGE = 1;

	/** @var int Not used within the database, only while displaying posts */
	public const THUMB = 4;

	/** @var int Browser-playable audio files */
	public const AUDIO = 7;

	/** @var int Browser-playable video files */
	public const VIDEO = 8;
}
class downloader
{
	/** @var config */
	protected $config;
	
	/**
	* Auth object
	* @var \phpbb\auth\auth
	*/
	protected $auth;
	
	/** @var content_visibility */
	protected $content_visibility;
	
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;
	
	/** @var \phpbb\request\request */
	protected $request;
	
	/**
	* phpBB root path
	* @var string
	*/
	protected $phpbb_root_path;
	
	/**
	* PHP file extension
	* @var string
	*/
	protected $php_ext;
	
	/** @var user */
	protected $user;
	
	
		/**
	 * Constructor
	 *
	 * @param auth					$auth
	 * @param service				$cache
	 * @param config				$config
	 * @param content_visibility	$content_visibility
	 * @param driver_interface		$db
	 * @param dispatcher_interface	$dispatcher
	 * @param language				$language
	 * @param request				$request
	 * @param storage				$storage
	 * @param symfony_request		$symfony_request
	 * @param user					$user
	 */
	 
	public function __construct(config $config, auth $auth, content_visibility $content_visibility, driver_interface $db, language $language, request $request, symfony_request $symfony_request, user $user)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->db = $db;
		$this->language = $language;
		$this->request = $request;
		$this->user = $user;
	}

	public function handle_download($file):Response
    {
		$attach_id = (int) $file;
		$thumbnail = $this->request->variable('t', false);
		$this->language->add_lang('viewtopic');
		
		if (!$this->config['allow_attachments'] && !$this->config['allow_pm_attach'])
		{
			throw new http_exception(404, 'ATTACHMENT_FUNCTIONALITY_DISABLED');
		}

		if (!$attach_id)
		{
			throw new http_exception(404, 'NO_ATTACHMENT_SELECTED');
		}
	
		$sql = 'SELECT attach_id, post_msg_id, topic_id, in_message, poster_id,
				is_orphan, physical_filename, real_filename, extension, mimetype,
				filesize, filetime
			FROM ' . ATTACHMENTS_TABLE . "
			WHERE attach_id = $attach_id";
		$result = $this->db->sql_query($sql);
		$attachment = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		
		if (!$attachment)
		{
			throw new http_exception(404, 'ERROR_NO_ATTACHMENT');		
		}
		
		$attachment['physical_filename'] = utf8_basename($attachment['physical_filename']);

		if ((!$attachment['in_message'] && !$this->config['allow_attachments']) ||
			($attachment['in_message'] && !$this->config['allow_pm_attach']))
		{
			throw new http_exception(404, 'ATTACHMENT_FUNCTIONALITY_DISABLED');
		}

		$this->phpbb_download_handle_forum_auth($attachment['topic_id']);
		$sql = 'SELECT forum_id, poster_id, post_visibility
			FROM ' . POSTS_TABLE . '
			WHERE post_id = ' . (int) $attachment['post_msg_id'];
		$result = $this->db->sql_query($sql);
		$post_row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post_row || !$this->content_visibility->is_visible('post', $post_row['forum_id'], $post_row))
		{
			// Attachment of a soft deleted post and the user is not allowed to see the post
			throw new http_exception(404, 'ERROR_NO_ATTACHMENT');
		}
		
		$extensions = array();
		if (!extension_allowed($post_row['forum_id'], $attachment['extension'], $extensions))
		{
			throw new http_exception(403, 'EXTENSION_DISABLED_AFTER_POSTING', [$attachment['extension']]);
		}
		
		$display_cat = $extensions[$attachment['extension']]['display_cat'];

		if ($thumbnail)
		{
			$attachment['physical_filename'] = 'thumb_' . $attachment['physical_filename'];
		}
		
		else if ($display_cat == attachment_category::NONE && !$attachment['is_orphan'])
		{
			if (!(($display_cat == attachment_category::IMAGE || $display_cat == attachment_category::THUMB) && !$this->user->optionget('viewimg')))
			{
				// Update download count
				$this->phpbb_increment_downloads($attachment['attach_id']);
			}
		}
		
		$response = new StreamedResponse();
		
		$response->headers->set('Content-Type', $attachment['mimetype']);

		// Display file types in browser and force download for others
		if (strpos($attachment['mimetype'], 'image') !== false
			|| strpos($attachment['mimetype'], 'audio') !== false
			|| strpos($attachment['mimetype'], 'video') !== false
		)
		{
			$disposition = $response->headers->makeDisposition(
				ResponseHeaderBag::DISPOSITION_INLINE,
				$attachment['real_filename'],
				$this->filenameFallback($attachment['real_filename'])
			);
		}
		else
		{
			$disposition = $response->headers->makeDisposition(
				ResponseHeaderBag::DISPOSITION_ATTACHMENT,
				$attachment['real_filename'],
				$this->filenameFallback($attachment['real_filename'])
			);
		}
		
		$response->headers->set('Content-Disposition', $disposition);
		
		$time = new \DateTime();
		$response->setExpires($time->modify('+1 year'));
		
		$physical_name = $attachment['physical_filename'];
		
		$response->setCallback(function () use ($physical_name) {
			readfile($this->config['s3_bucket_link'].''. $physical_name);
			// Terminate script to avoid the execution of terminate events
			// This avoid possible errors with db connection closed
			//exit;
		});
		return $response;
		
	}
	
	/**
	 * Remove non valid characters https://github.com/symfony/http-foundation/commit/c7df9082ee7205548a97031683bc6550b5dc9551
	 */
	protected function filenameFallback($filename)
	{
		$filename = preg_replace(['/[^\x20-\x7e]/', '/%/', '/\//', '/\\\\/'], '', $filename);

		return (!empty($filename)) ?: 'File';
	}
	
	protected function phpbb_download_handle_forum_auth(int $topic_id): void
	{
		$sql_array = [
			'SELECT'	=> 't.forum_id, t.topic_poster, t.topic_visibility, f.forum_name, f.forum_password, f.parent_id',
			'FROM'		=> [
				TOPICS_TABLE => 't',
				FORUMS_TABLE => 'f',
			],
			'WHERE'		=> 't.topic_id = ' . (int) $topic_id . '
				AND t.forum_id = f.forum_id',
		];
		$sql = $this->db->sql_build_query('SELECT', $sql_array);
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row && !$this->content_visibility->is_visible('topic', $row['forum_id'], $row))
		{
			throw new http_exception(404, 'ERROR_NO_ATTACHMENT');
		}
		else if ($row && $this->auth->acl_get('u_download') && $this->auth->acl_get('f_download', $row['forum_id']))
		{
			if ($row['forum_password'])
			{
				// Do something else ... ?
				login_forum_box($row);
			}
		}
		else
		{
			throw new http_exception(403, 'SORRY_AUTH_VIEW_ATTACH');
		}
	}
	
	protected function phpbb_increment_downloads(int $id): void
	{
		$sql = 'UPDATE ' . ATTACHMENTS_TABLE . '
			SET download_count = download_count + 1
			WHERE attach_id = ' . $id;
		$this->db->sql_query($sql);
	}
}