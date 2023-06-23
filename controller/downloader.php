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
use Symfony\Component\HttpFoundation\Request as symfony_request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;


/**
 * Event listener
 */
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
	 
	public function __construct(config $config, auth $auth, content_visibility $content_visibility, driver_interface $db, language $language, request $request, symfony_request $symfony_request)
	{
		//parent::__construct($cache, $db, $storage, $symfony_request);

		$this->auth = $auth;
		$this->config = $config;
		$this->content_visibility = $content_visibility;
		$this->db = $db;
		$this->language = $language;
		$this->request = $request;
	}

	// public function __construct($config, $auth, $db, $request, $phpbb_root_path, $php_ext)
	// {
		// $this->config = $config;
		// $this->auth = $auth;
		// $this->db = $db;
		// $this->request = $request;
		// $this->phpbb_root_path = $phpbb_root_path;
		// $this->php_ext = $phpEx;
	// }
	
	/**
	 * {@inheritdoc}
	 */
	public function handle_download($filename):Response
    {
		$this->language->add_lang('viewtopic');
		
		//real_name, $physical_name, $mimetype, $topic_id $post_id
		$physical_name = $this->request->variable('physical_name', '');
		$mimetype = $this->request->variable('mimetype', '');
		$topic_id = $this->request->variable('topic_id', 0);
		$post_id = $this->request->variable('post_id', '');
		
		if (!$filename)
		{
			//send_status_line(404, 'Not Found');
			throw new http_exception(404, 'ERROR_NO_ATTACHMENT');
			
		}
		//require($phpbb_root_path . 'includes/functions_download' . '.' . $phpEx);
		//phpbb_download_handle_forum_auth($db, $auth, $topic_id);

		$this->phpbb_download_handle_forum_auth($topic_id);
		$sql = 'SELECT forum_id, poster_id, post_visibility
			FROM ' . POSTS_TABLE . '
			WHERE post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post_row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post_row || !$this->content_visibility->is_visible('post', $post_row['forum_id'], $post_row))
		{
			// Attachment of a soft deleted post and the user is not allowed to see the post
			throw new http_exception(404, 'ERROR_NO_ATTACHMENT');
		}
		
		$response = new StreamedResponse();

		// Content-type header
		$response->headers->set('Content-Type', $mimetype);
		
				if (strpos($mimetype, 'image') !== false
			|| strpos($mimetype, 'audio') !== false
			|| strpos($mimetype, 'video') !== false
		)
		{
			$disposition = $response->headers->makeDisposition(
				ResponseHeaderBag::DISPOSITION_INLINE,
				$filename,
				$this->filenameFallback($filename)
			);
		}
		else
		{
			$disposition = $response->headers->makeDisposition(
				ResponseHeaderBag::DISPOSITION_ATTACHMENT,
				$filename,
				$this->filenameFallback($filename)
			);
		}
		$response->headers->set('Content-Disposition', $disposition);
		
		$time = new \DateTime();
		$response->setExpires($time->modify('+1 year'));
		
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
}