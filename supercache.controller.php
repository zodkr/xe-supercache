<?php

/**
 * Super Cache module: controller class
 * 
 * Copyright (c) 2016 Kijin Sung <kijin@kijinsung.com>
 * All rights reserved.
 * 
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 * for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
class SuperCacheController extends SuperCache
{
	/**
	 * The maximum supported page.
	 */
	protected $_maxSupportedPage = 1;
	
	/**
	 * Flag to cache the current request.
	 */
	protected $_cacheCurrentRequest = null;
	protected $_cacheStartTimestamp = null;
	
	/**
	 * Trigger called at moduleHandler.init (before)
	 */
	public function triggerBeforeModuleHandlerInit($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Check the default URL.
		if ($config->redirect_to_default_url && Context::getRequestMethod() === 'GET')
		{
			$current_domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
			$default_url = parse_url(Context::getDefaultUrl());
			if ($current_domain !== $default_url['host'])
			{
				$redirect_url = sprintf('%s://%s%s%s', $default_url['scheme'], $default_url['host'], $default_url['port'] ? (':' . $default_url['port']) : '', $_SERVER['REQUEST_URI']);
				return $this->terminateRedirectTo($redirect_url);
			}
			else
			{
				$default_url_checked = true;
			}
		}
		else
		{
			$default_url_checked = false;
		}
		
		// Check the full page cache.
		if ($config->full_cache)
		{
			$this->checkFullCache($obj, $config, $default_url_checked);
		}
	}
	
	/**
	 * Trigger called at moduleObject.proc (before)
	 */
	public function triggerBeforeModuleObjectProc($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Fill the page variable for paging cache.
		if ($config->paging_cache)
		{
			$this->fillPageVariable($obj, $config);
		}
	}
	
	/**
	 * Trigger called at document.getDocumentList (before)
	 */
	public function triggerBeforeGetDocumentList($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		if (!$config->paging_cache || (!$obj->mid && !$obj->module_srl))
		{
			return;
		}
		
		// If this is a POST search request (often caused by sketchbook skin), abort to prevent double searching.
		if ($config->disable_post_search && $_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' && !$_POST['act'] && $obj->search_keyword)
		{
			return $this->terminateRequest('disable_post_search');
		}

		// Abort if this request is for any page greater than 1.
		if ($obj->page > $this->_maxSupportedPage && !$config->paging_cache_use_offset)
		{
			return;
		}
		
		// Abort if there are any search terms other than module_srl and category_srl.
		if ($obj->search_target || $obj->search_keyword || $obj->exclude_module_srl || $obj->start_date || $obj->end_date || $obj->member_srl)
		{
			return;
		}
		
		// Abort if there are any other unusual search options.
		$oDocumentModel = getModel('document');
		$oDocumentModel->_setSearchOption($obj, $args, $query_id, $use_division);
		if ($query_id !== 'document.getDocumentList' || $use_division || (is_array($args->module_srl) && count($args->module_srl) > 1))
		{
			return;
		}
		
		// Abort if the module is excluded by configuration.
		if (isset($config->paging_cache_exclude_modules[$args->module_srl]))
		{
			return;
		}
		
		// Abort if the module/category has fewer documents than the threshold.
		$oModel = getModel('supercache');
		$document_count = $oModel->getDocumentCount($args->module_srl, $args->category_srl);
		if ($document_count < $config->paging_cache_threshold)
		{
			return;
		}
		
		// Add offset to simulate paging.
		if ($config->paging_cache_use_offset && $args->page > 1)
		{
			$args->list_offset = ($args->page - 1) * $args->list_count;
		}
		
		// Get documents and replace the output.
		$obj->use_alternate_output = $oModel->getDocumentList($args, $document_count);
	}
	
	/**
	 * Trigger called at document.insertDocument (after)
	 */
	public function triggerAfterInsertDocument($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Update document count for pagination cache.
		$oModel = getModel('supercache');
		$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, 1);
		
		// Refresh full page cache for the current module and/or index module.
		if ($config->full_cache && $config->full_cache_document_action)
		{
			if (isset($config->full_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0, 1);
			}
			if (isset($config->full_cache_document_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_document_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0, 1);
				}
			}
		}
	}
	
	/**
	 * Trigger called at document.updateDocument (after)
	 */
	public function triggerAfterUpdateDocument($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Get the old and new values of module_srl and category_srl.
		$original = getModel('document')->getDocument($obj->document_srl);
		$original_module_srl = intval($original->get('module_srl'));
		$original_category_srl = intval($original->get('category_srl'));
		$new_module_srl = intval($obj->module_srl) ?: $original_module_srl;
		$new_category_srl = intval($obj->category_srl) ?: $original_category_srl;
		
		// Update document count for pagination cache.
		$oModel = getModel('supercache');
		if ($original_module_srl !== $new_module_srl || $original_category_srl !== $new_category_srl)
		{
			$oModel->updateDocumentCount($new_module_srl, $new_category_srl, 1);
			if ($original_module_srl)
			{
				$oModel->updateDocumentCount($original_module_srl, $original_category_srl, -1);
			}
		}
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_document_action)
		{
			if (isset($config->full_cache_document_action['refresh_document']))
			{
				$oModel->deleteFullPageCache($original_module_srl, $obj->document_srl, 1);
				if ($original_module_srl !== $new_module_srl)
				{
					$oModel->deleteFullPageCache($new_module_srl, $obj->document_srl, 1);
				}
			}
			if (isset($config->full_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($original_module_srl, 0, 1);
				if ($original_module_srl !== $new_module_srl)
				{
					$oModel->deleteFullPageCache($new_module_srl, 0, 1);
				}
			}
			if (isset($config->full_cache_document_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if (($index_module_srl != $original_module_srl && $index_module_srl != $new_module_srl) || !isset($config->full_cache_document_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0, 1);
				}
			}
		}
	}
	
	/**
	 * Trigger called at document.deleteDocument (after)
	 */
	public function triggerAfterDeleteDocument($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Update document count for pagination cache.
		$oModel = getModel('supercache');
		$oModel->updateDocumentCount($obj->module_srl, $obj->category_srl, -1);
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_document_action)
		{
			if (isset($config->full_cache_document_action['refresh_document']))
			{
				$oModel->deleteFullPageCache($obj->module_srl, $obj->document_srl, 1);
			}
			if (isset($config->full_cache_document_action['refresh_module']) && $obj->module_srl)
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0, 1);
			}
			if (isset($config->full_cache_document_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_document_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0, 1);
				}
			}
		}
	}
	
	/**
	 * Trigger called at document.copyDocumentModule (after)
	 */
	public function triggerAfterCopyDocumentModule($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at document.moveDocumentModule (after)
	 */
	public function triggerAfterMoveDocumentModule($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at document.moveDocumentToTrash (after)
	 */
	public function triggerAfterMoveDocumentToTrash($obj)
	{
		$this->triggerAfterDeleteDocument($obj);
	}
	
	/**
	 * Trigger called at document.restoreTrash (after)
	 */
	public function triggerAfterRestoreDocumentFromTrash($obj)
	{
		$this->triggerAfterUpdateDocument($obj);
	}
	
	/**
	 * Trigger called at comment.insertComment (after)
	 */
	public function triggerAfterInsertComment($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_comment_action)
		{
			$oModel = getModel('supercache');
			if (isset($config->full_cache_comment_action['refresh_document']))
			{
				$oModel->deleteFullPageCache($obj->module_srl, $obj->document_srl, 1);
			}
			if (isset($config->full_cache_comment_action['refresh_module']))
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0, 1);
			}
			if (isset($config->full_cache_comment_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_comment_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0, 1);
				}
			}
		}
	}
	
	/**
	 * Trigger called at comment.updateComment (after)
	 */
	public function triggerAfterUpdateComment($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_comment_action)
		{
			$original = getModel('comment')->getComment($obj->comment_srl);
			$document_srl = $obj->document_srl ?: $original->document_srl;
			$module_srl = $obj->module_srl ?: $original->module_srl;
			
			$oModel = getModel('supercache');
			if (isset($config->full_cache_comment_action['refresh_document']))
			{
				$oModel->deleteFullPageCache($module_srl, $document_srl, 1);
			}
			if (isset($config->full_cache_comment_action['refresh_module']))
			{
				$oModel->deleteFullPageCache($module_srl, 0, 1);
			}
			if (isset($config->full_cache_comment_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $module_srl || !isset($config->full_cache_comment_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0, 1);
				}
			}
		}
	}
	
	/**
	 * Trigger called at comment.deleteComment (after)
	 */
	public function triggerAfterDeleteComment($obj)
	{
		// Get module configuration.
		$config = $this->getConfig();
		
		// Refresh full page cache for the current document, module, and/or index module.
		if ($config->full_cache && $config->full_cache_comment_action)
		{
			$oModel = getModel('supercache');
			if (isset($config->full_cache_comment_action['refresh_document']))
			{
				$oModel->deleteFullPageCache($obj->module_srl, $obj->document_srl, 1);
			}
			if (isset($config->full_cache_comment_action['refresh_module']))
			{
				$oModel->deleteFullPageCache($obj->module_srl, 0, 1);
			}
			if (isset($config->full_cache_comment_action['refresh_index']))
			{
				$index_module_srl = Context::get('site_module_info')->index_module_srl ?: 0;
				if ($index_module_srl != $obj->module_srl || !isset($config->full_cache_comment_action['refresh_module']))
				{
					$oModel->deleteFullPageCache($index_module_srl, 0, 1);
				}
			}
		}
	}
	
	/**
	 * Trigger called at display (after)
	 */
	public function triggerAfterDisplay($obj)
	{
		if ($this->_cacheCurrentRequest)
		{
			getModel('supercache')->setFullPageCache(
				$this->_cacheCurrentRequest[0],
				$this->_cacheCurrentRequest[1],
				$this->_cacheCurrentRequest[2],
				$this->_cacheCurrentRequest[3],
				$obj,
				microtime(true) - $this->_cacheStartTimestamp
			);
		}
	}
	
	/**
	 * Check the full page cache for the current request,
	 * and terminate the request with a cached response if available.
	 * 
	 * @param object $obj
	 * @param object $config
	 * @param object $default_url_checked (optional)
	 * @return void
	 */
	public function checkFullCache($obj, $config, $default_url_checked = false)
	{
		// Abort if not an HTML GET request.
		if (Context::getRequestMethod() !== 'GET')
		{
			return;
		}
		
		// Abort if logged in.
		$logged_info = Context::get('logged_info');
		if ($logged_info && $logged_info->member_srl)
		{
			return;
		}
		
		// Abort if the user agent is excluded.
		$is_crawler = isCrawler();
		if ($is_crawler && !isset($config->full_cache['robot']))
		{
			return;
		}
		$is_mobile = Mobile::isFromMobilePhone() ? true : false;
		if (($is_mobile && !isset($config->full_cache['mobile'])) || (!$is_mobile && !isset($config->full_cache['pc'])))
		{
			return;
		}
		
		// Abort if the current domain does not match the default domain.
		$is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && $_SERVER['HTTPS'] !== 'off');
		$site_module_info = Context::get('site_module_info');
		if (!$default_url_checked)
		{
			$current_domain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
			$default_domain = parse_url(Context::getDefaultUrl(), PHP_URL_HOST);
			if ($current_domain !== $default_domain && $current_domain !== parse_url($site_module_info->domain, PHP_URL_HOST))
			{
				return;
			}
		}
		
		// Abort if the current act is excluded.
		if (isset($config->full_cache_exclude_acts[$obj->act]))
		{
			return;
		}
		
		// Abort if the current module is excluded.
		if (!$obj->mid && !$obj->module && !$obj->module_srl)
		{
			$module_srl = $site_module_info->module_srl;
		}
		elseif ($obj->module_srl)
		{
			$module_srl = $obj->module_srl;
		}
		elseif ($obj->mid)
		{
			$module_info = getModel('module')->getModuleInfoByMid($obj->mid, intval($site_module_info->site_srl) ?: 0);
			$module_srl = $module_info ? $module_info->module_srl : 0;
		}
		else
		{
			$module_srl = 0;
		}
		
		$module_srl = intval($module_srl);
		if (!$module_srl || isset($config->full_cache_exclude_modules[$module_srl]))
		{
			return;
		}
		
		// Determine the page type.
		if ($obj->act)
		{
			$page_type = 'other';
		}
		elseif ($obj->document_srl)
		{
			$page_type = 'document';
		}
		elseif ($module_srl)
		{
			$page_type = 'module';
		}
		else
		{
			return;
		}
		
		// Abort if the current page type is not selected for caching.
		if (!isset($config->full_cache_type[$page_type]))
		{
			return;
		}
		
		// Remove unnecessary request variables.
		$request_vars = Context::getRequestVars();
		if (is_object($request_vars))
		{
			$request_vars = get_object_vars($request_vars);
		}
		unset($request_vars['mid'], $request_vars['module'], $request_vars['module_srl'], $request_vars['document_srl']);
		
		// Check the cache.
		$oModel = getModel('supercache');
		switch ($page_type)
		{
			case 'module':
				$this->_cacheCurrentRequest = array($module_srl, 0, $is_mobile, $request_vars);
				$cache = $oModel->getFullPageCache($module_srl, 0, $is_mobile, $request_vars);
				break;
			case 'document':
				$this->_cacheCurrentRequest = array($module_srl, $obj->document_srl, $is_mobile, $request_vars);
				$cache = $oModel->getFullPageCache($module_srl, $obj->document_srl, $is_mobile, $request_vars);
				break;
			case 'other':
				$this->_cacheCurrentRequest = array(0, 0, $is_mobile, $request_vars);
				$cache = $oModel->getFullPageCache(0, 0, $is_mobile, $request_vars);
				break;
		}
		
		// If cached content is available, print it and exit.
		if ($cache)
		{
			$expires = max(0, $cache['expires'] - time());
			if ($config->full_cache_use_headers)
			{
				$this->printCacheControlHeaders($page_type, $expires, $config->full_cache_stampede_protection ? 10 : 0);
			}
			else
			{
				header('X-SuperCache: type=' . $page_type . '; expires=' . $expires);
			}
			
			if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $cache['cached'])
			{
				header('HTTP/1.1 304 Not Modified');
			}
			else
			{
				header("Content-Type: text/html; charset=UTF-8");
				echo $cache['content'];
				echo "\n" . '<!--' . "\n";
				echo '    Serving ' . strlen($cache['content']) . ' bytes from full page cache' . "\n";
				echo '    Generated at ' . date('Y-m-d H:i:s P', $cache['cached']) . ' in ' . $cache['elapsed'] . "\n";
				echo '    Cache expires in ' . $expires . ' seconds' . "\n";
				echo '-->' . "\n";
			}
			Context::close();
			exit;
		}
		
		// Otherwise, prepare headers to cache the current request.
		if ($config->full_cache_use_headers)
		{
			$this->printCacheControlHeaders($page_type, $config->full_cache_duration, $config->full_cache_stampede_protection ? 10 : 0);
		}
		else
		{
			header('X-SuperCache: type=' . $page_type . '; expires=' . $config->full_cache_duration);
		}
		$this->_cacheStartTimestamp = microtime(true);
	}
	
	/**
	 * Print cache control headers.
	 * 
	 * @param string $page_type
	 * @param int $expires
	 * @param int $scatter
	 * @return void
	 */
	public function printCacheControlHeaders($page_type, $expires, $scatter)
	{
		$scatter = intval($expires * ($scatter / 100));
		$expires = intval($expires - mt_rand(0, $scatter));
		header('X-SuperCache: type=' . $page_type . '; expires=' . $config->full_cache_duration);
		header('Cache-Control: max-age=' . $expires);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
		header_remove('Pragma');
	}
	
	/**
	 * If this is a document view request without a page number,
	 * fill in the page number to prevent the getDocumentListPage query.
	 * 
	 * @param object $obj
	 * @param object $config
	 * @return void
	 */
	public function fillPageVariable($obj, $config)
	{
		// Only work if there is a document_srl without a page variable and a suitable referer header.
		if (Context::get('document_srl') && !Context::get('page') && ($referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : false))
		{
			// Check the module and act.
			if (preg_match('/^(?:board|bodex|beluxe)\.disp(?:board|bodex|beluxe)content/i', $obj->module_info->module . '.' . $obj->act))
			{
				// Only guess the page number from the same module in the same site.
				if (strpos($referer, '//' . $_SERVER['HTTP_HOST'] . '/') === false)
				{
					return;
				}
				elseif (preg_match('/\/([a-zA-Z0-9_-]+)(?:\?|(?:\/\d+)?$)/', $referer, $matches) && $matches[1] === $obj->mid)
				{
					Context::set('page', 1);
				}
				elseif (preg_match('/\bmid=([a-zA-Z0-9_-]+)\b/', $referer, $matches) && $matches[1] === $obj->mid)
				{
					if (preg_match('/\bpage=(\d+)\b/', $referer, $matches))
					{
						Context::set('page', $matches[1]);
					}
					else
					{
						Context::set('page', 1);
					}
				}
			}
		}
	}
	
	/**
	 * Terminate the current request.
	 * 
	 * @param string $reason
	 * @param array $data (optional)
	 * @return exit
	 */
	public function terminateRequest($reason = '', $data = array())
	{
		$output = new Object;
		$output->add('supercache_terminated', $reason);
		foreach ($data as $key => $value)
		{
			$output->add($key, $value);
		}
		$oDisplayHandler = new DisplayHandler;
		$oDisplayHandler->printContent($output);
		Context::close();
		exit;
	}
	
	/**
	 * Terminate the current request by redirecting to another URL.
	 * 
	 * @param string $url
	 * @param int $status (optional)
	 * @return exit
	 */
	public function terminateRedirectTo($url, $status = 301)
	{
		if ($status === 301)
		{
			header('HTTP/1.1 301 Moved Permanently');
		}
		else
		{
			header('HTTP/1.1 302 Found');
		}
		
		header('Location: ' . $url);
		header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
		header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
		header_remove('Pragma');
		exit;
	}
}
