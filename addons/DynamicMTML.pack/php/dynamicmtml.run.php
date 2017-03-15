<?php
    if (! ini_get( 'date.timezone' ) ) {
        date_default_timezone_set( 'Asia/Tokyo' );
    }
    $plugin_path = dirname( __File__ ) . DIRECTORY_SEPARATOR;
    require_once( $plugin_path . 'dynamicmtml.util.php' );
    require_once( $plugin_path . 'class.dynamicmtml.php' );
    if (! isset( $mt_dir ) ) $mt_dir = dirname( dirname( dirname( $plugin_path ) ) );
    require_once( $mt_dir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'MTUtil.php' );
    if (! isset( $mt_config ) ) $mt_config = $mt_dir . DIRECTORY_SEPARATOR . 'mt-config.cgi';
    global $mt;
    global $ctx;
    $ctx = NULL;
    $app = new DynamicMTML();
    $app->configure( $mt_config );
    $no_database = FALSE;
    $dynamic_config = $app->config;
    if (! $app->config( 'Database' ) || (! isset( $blog_id ) ) ) {
        $no_database = TRUE;
        $app->stash( 'no_database', 1 );
        require_once( $plugin_path . 'mt.php' );
        $mt = new MT();
    }
    $include_static   = $app->config( 'DynamicIncludeStatic' );
    $dynamicphpfirst  = $app->config( 'DynamicPHPFirst' );
    $allow_magicquote = $app->config( 'AllowMagicQuotesGPC' );
    if (! $allow_magicquote ) {
        if ( get_magic_quotes_gpc() ) {
            function strip_magic_quotes_slashes ( $arr ) {
                return is_array( $arr ) ?
                array_map( 'strip_magic_quotes_slashes', $arr ) :
                stripslashes( $arr );
            }
            $_GET = strip_magic_quotes_slashes( $_GET );
            $_POST = strip_magic_quotes_slashes( $_POST );
            $_REQUEST = strip_magic_quotes_slashes( $_REQUEST );
            $_COOKIE = strip_magic_quotes_slashes( $_COOKIE );
        }
    }
    if ( isset( $_SERVER[ 'REDIRECT_STATUS' ] ) ) {
        $status = $_SERVER[ 'REDIRECT_STATUS' ];
        if ( ( $status == 403 ) || ( $status == 404 ) ) {
            if ( isset( $_SERVER[ 'REDIRECT_QUERY_STRING' ] ) ) {
                if (! $_GET ) {
                    parse_str( $_SERVER[ 'REDIRECT_QUERY_STRING' ], $_GET );
                }
            }
            if (! $_POST ) {
                if ( $params = file_get_contents( "php://input" ) ) {
                    parse_str( $params, $_POST );
                }
            }
            $app->request_method = $_SERVER[ 'REDIRECT_REQUEST_METHOD' ];
            $app->mod_rewrite = 0;
        } else {
            $app->request_method = $_SERVER[ 'REQUEST_METHOD' ];
            $app->mod_rewrite = 1;
        }
    } else {
        $app->mod_rewrite = 1;
    }
    $app->run_callbacks( 'init_app' );
    $secure = !empty( $_SERVER[ 'HTTPS' ] ) && strtolower( $_SERVER[ 'HTTPS' ] ) !== 'off'
            ? 's' : '';
    $base   = "http{$secure}://{$_SERVER[ 'SERVER_NAME' ]}";
    $port   = ( int ) $_SERVER[ 'SERVER_PORT' ];
    if (! empty( $port ) && $port !== ( $secure === '' ? 80 : 443 ) ) $base .= ":$port";
    $request_uri = NULL;
    if ( isset( $_SERVER[ 'HTTP_X_REWRITE_URL' ] ) ) {
        // IIS with ISAPI_Rewrite
        $request_uri  = $_SERVER[ 'HTTP_X_REWRITE_URL' ];
    } elseif ( isset( $_SERVER[ 'REQUEST_URI' ] ) ) {
        // Apache and others.
        $request_uri  = $_SERVER[ 'REQUEST_URI' ];
    } elseif ( isset( $_SERVER[ 'HTTP_X_ORIGINAL_URL' ] ) ) {
        // Other IIS.
        $request_uri = $_SERVER[ 'HTTP_X_ORIGINAL_URL' ];
        $_SERVER[ 'REQUEST_URI' ] = $_SERVER[ 'HTTP_X_ORIGINAL_URL' ];
        if ( isset( $_SERVER[ 'QUERY_STRING' ] ) ) {
            $request_uri .= '?' . $_SERVER[ 'QUERY_STRING' ];
            if (! $_GET ) {
                parse_str( $_SERVER[ 'QUERY_STRING' ], $_GET );
            }
            if (! $_COOKIE ) {
                $cookies = explode( ';', $_SERVER[ 'HTTP_COOKIE' ] );
                foreach ( $cookies as $cookie_str ) {
                    list( $key, $value ) = explode( '=', trim( $cookie_str ) );
                    $_COOKIE[ $key ] = trim( $value );
                }
            }
        }
    } elseif ( isset( $_SERVER[ 'ORIG_PATH_INFO' ] ) ) {
        // IIS 5.0, PHP as CGI.
        $request_uri = $_SERVER[ 'ORIG_PATH_INFO' ];
        if (! empty( $_SERVER[ 'QUERY_STRING' ] ) ) {
            $request_uri .= '?' . $_SERVER[ 'QUERY_STRING' ];
        }
    }
    $root = $app->config( 'ServerDocumentRoot' );
    if (! isset( $root ) ) {
        $root = $_SERVER[ 'DOCUMENT_ROOT' ];
    }
    $root = $app->chomp_dir( $root );
    if ( isset( $alias_name ) ) {
        $alias_original = $root . $app->chomp_dir( $alias_name );
    }
    $ctime        = empty( $_SERVER[ 'REQUEST_TIME' ] )
                  ? time() : $_SERVER[ 'REQUEST_TIME' ];
    $request      = NULL;
    $path_info    = NULL;
    $text         = NULL;
    $param        = NULL;
    $orig_mtime   = NULL;
    $clear_cache  = NULL;
    $result_type  = NULL;
    $build_type   = NULL;
    $data         = NULL;
    $dynamicmtml  = FALSE;
    $preview      = FALSE;
    $get_preview  = FALSE;
    $file_exists  = FALSE;
    $is_secure    = NULL; if ( $secure ) { $is_secure = 1; }
    if (! isset( $extension ) ) $extension = 'html';
    if (! isset( $use_cache ) ) $use_cache = 0;
    if (! isset( $conditional ) ) $conditional = 0;
    if (! isset( $indexes ) ) $indexes = 'index.html';
    if (! isset( $size_limit ) ) $size_limit = 524288;
    if (! isset( $server_cache ) ) $server_cache = 7200;
    if (! isset( $excludes ) ) $excludes = 'php';
    if (! isset( $require_login ) ) $require_login = FALSE;
    if (! isset( $dynamic_caching ) ) $dynamic_caching = FALSE;
    if (! isset( $dynamic_conditional ) ) $dynamic_conditional = FALSE;
    // ========================================
    // Check Request and Set Parameter
    // ========================================
    if ( strpos( $request_uri, '?' ) ) {
        list( $request, $param ) = explode( '?', $request_uri );
        $app->stash( 'query_string', $param );
    } else {
        $request = $request_uri;
        $param = NULL;
    }
    if ( strpos( dirname( $request_uri ), '.' ) !== FALSE ) {
        $request_paths = explode( '/', $request_uri );
        $curr_paths = $request_paths;
        $paths = '';
        foreach ( $request_paths as $item ) {
            if ( $item ) $paths .= '/' . $item;
            $curr_paths = array_slice( $curr_paths, 1 );
            if ( strpos( $item, '.' ) !== FALSE ) {
                $request_file = $root . $paths;
                if ( is_file( $request_file ) ) {
                    $path_info = '/' . join( '/', $curr_paths );
                    $request = $paths;
                    $app->path_info = $path_info;
                    $app->stash( 'path_info', $path_info );
                    break;
                }
            }
        }
    }
    $url = $base . $request_uri;
    // ========================================
    // Set File and Content_type
    // ========================================
    $file = $root . DIRECTORY_SEPARATOR . $request;
    $cache_dir = $app->stash( 'powercms_files_dir' ) . DIRECTORY_SEPARATOR . 'cache';
    // $app->chomp_dir( $cache_dir );
    $file = $app->adjust_file( $file, $indexes, $alias_original, $alias_path );
    $static_path = $app->__add_slash( $app->config( 'StaticFilePath' ) );
    $app->check_excludes( $file, $excludes, $mt_dir, $static_path );
    if (! is_null( $file ) ) {
        $pinfo = pathinfo( $file );
        if ( isset( $pinfo[ 'extension' ] ) ) {
            $extension = $pinfo[ 'extension' ];
        }
    }
    $contenttype = $app->get_mime_type( $extension );
    if ( file_exists( $file ) ) {
        $file_exists = TRUE;
    }
    if ( $param ) {
        $pos = strpos( basename( $request ), 'mt-preview-' );
        if ( $pos === 0 ) {
            $check_param = str_replace( '&smartphone_preview=1', '', $param );
            if ( ctype_digit( $check_param ) ) {
                $use_cache = 0;
                $clear_cache = 1;
                $app->stash( 'preview', 1 );
                $preview = 1;
                if (! $file_exists ) {
                    $get_preview = 1;
                }
            }
        }
    }
    if ( (! $file_exists ) && (! $preview ) ) {
        if ( isset( $_SERVER[ 'HTTP_REFERER' ] ) ) {
            $referer = $_SERVER[ 'HTTP_REFERER' ];
            // TODO::https
            if ( strpos( $referer, $base ) === 0 ) {
                if ( strpos( $referer, '?' ) !== FALSE ) {
                    list ( $ref_req, $ref_param ) = explode( '?', $referer );
                    if ( $ref_param ) {
                        $pos = strpos( basename( $ref_req ), 'mt-preview-' );
                        if ( $pos === 0 ) {
                            $ref_param = str_replace( '&smartphone_preview=1', '', $ref_param );
                            if ( ctype_digit( $ref_param ) ) {
                                $get_preview = 1;
                            }
                        }
                    }
                }
            }
        }
    }
    if ( $get_preview ) {
        require_once( 'dynamicmtml.get_preview.php' );
    }
    $type_text = $app->type_text( $contenttype );
    $path = preg_replace( '!(/[^/]*$)!', '', $request );
    $path .= '/';
    $script = preg_replace( '!(^.*?/)([^/]*$)!', '$2', $request );
    if ( $file_exists ) {
        $orig_mtime = filemtime( $file );
        $app->stash( 'filemtime', $orig_mtime );
    }
    // ========================================
    // Include DPAPI
    // ========================================
    $blog          = NULL;
    $force_compile = NULL;
    $args = array( 'blog_id' => $blog_id,
                   'conditional' => $conditional,
                   'use_cache' => $use_cache,
                   'root' => $root,
                   'cache_dir' => $cache_dir,
                   'plugin_path' => $plugin_path,
                   'file' => $file,
                   'base' => $base,
                   'path' => $path,
                   'script' => $script,
                   'request' => $request,
                   'path_info' => $path_info,
                   'param' => $param,
                   'is_secure' => $is_secure,
                   'url' => $url,
                   'contenttype' => $contenttype,
                   'extension' => $extension );
    $app->init( $args );
    $app->run_callbacks( 'pre_run', $mt, $ctx, $args );
    require_once $mt_dir . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'class.exception.php';
    if (! $mt ) {
        require_once( 'mt.php' );
        try {
            $mt = MT::get_instance( $blog_id, $mt_config );
        } catch ( MTInitException $e ) {
            $app->run_callbacks( 'mt_init_exception', $mt, $ctx, $args, $e );
            if ( (! isset( $mt ) ) && $require_login ) {
                // for PowerCMS Professional
                $app->service_unavailable();
            }
        }
    }
    if ( isset( $mt ) ) {
        $ctx =& $mt->context();
        if ( $app->config( 'DynamicGenerateDirectories' ) ) {
            $app->stash( 'generate_directories', 1 );
        }
        if (! $no_database ) {
            set_error_handler( array( &$mt, 'error_handler' ) );
            $driver = $app->config( 'objectdriver' );
            $driver = preg_replace( '/^DB[ID]::/', '', $driver );
            $driver or $driver = 'mysql';
            $driver = strtolower( $driver );
            $cfg =& $app->config;
            $cfg[ 'dbdriver' ] = $driver;
            if ( $driver == 'mysql' or $driver == 'postgres' ) {
                $mt->db()->set_names( $mt );
            }
            $app->run_callbacks( 'init_db', $mt, $ctx, $args );
            if (! $blog = $ctx->stash( 'blog' ) ) {
                $blog = $mt->db()->fetch_blog( $blog_id );
            }
            $ctx->stash( 'blog', $blog );
            $ctx->stash( 'blog_id', $blog_id );
            $app->stash( 'blog', $blog );
            $app->stash( 'blog_id', $blog_id );
            $app->set_context( $mt, $ctx, $blog_id );
        } else {
            $ctx->stash( 'no_database', 1 );
            $app->set_context( $mt, $ctx );
        }
        $later = $app->config( 'DynamicInitPluginsLater' );
        $mt->config( 'DynamicInitPluginsLater', $later );
        if (! $later ) {
            $mt->init_plugins();
        }
        $ctx->stash( 'callback_dir', $app->stash( 'callback_dir' ) );
        $ctx->stash( 'preview', $preview );
        $ctx->stash( 'content_type', $contenttype );
        $base_original = $root;
        $request_original = $request;
        $app->run_callbacks( 'post_init', $mt, $ctx, $args );
        if ( ( $base_original != $root ) || ( $request_original != $request ) ) {
            $file = $root . DIRECTORY_SEPARATOR . $request;
            $file = $app->adjust_file( $file, $indexes, $alias_original, $alias_path );
            $app->stash( 'file', $file );
            $app->stash( 'root', $root );
            $app->stash( 'request', $request );
        }
        $cfg_forcecompile = $app->config( 'DynamicForceCompile' );
        if ( $cfg_forcecompile ) {
            $force_compile = 1;
        }
    }
    // ========================================
    // Search Cache
    // ========================================
    $cache = $app->stash( 'cache' );
    if (! $cache ) {
        $cache = $app->cache_filename( $blog_id, $file . $path_info, $param );
        $app->stash( 'cache', $cache );
    }
    $args[ 'cache' ] = $cache;
    if ( $use_cache && file_exists( $cache ) ) {
        // require_once( $plugin_path . 'dynamicmtml.check_cache.php' );
        $mtime = filemtime( $cache );
        if ( ( $ctime - $mtime ) > $server_cache ) {
            unlink( $cache );
        } elseif ( $orig_mtime > $mtime ) {
            unlink( $cache );
            $force_compile = 1;
            $app->stash( 'force_compile', 1 );
        } else {
            if ( $conditional ) {
                $app->do_conditional( filemtime( $cache ) );
            }
            $app->send_http_header( $contenttype, $mtime, filesize( $cache ) );
            $app->echo_file_get_contents( $cache, $size_limit );
            exit();
        }
    }
    // ========================================
    // Include PHP if Directory Index
    // ========================================
    $app->include_php( $file );
    // ========================================
    // Run DynamicMTML
    // ========================================
    if ( isset( $mt ) && $force_compile ) {
        $app->stash( 'force_compile', 1 );
        $ctx->force_compile = TRUE;
    }
    if ( $app->stash( 'perlbuild' ) ) {
        require_once( $plugin_path . 'dynamicmtml.perlbuilder.php' );
        $app->run_callbacks( 'post_perlbuild', $mt, $ctx, $args, $text );
    }
    if ( $blog ) {
        if ( $blog->dynamic_mtml || $require_login ) {
            $dynamicmtml = TRUE;
        }
    } else {
        $dynamicmtml = TRUE;
    }
    if ( file_exists( $file ) && $dynamicmtml ) {
        if ( $app->config( 'Database' ) ) {
            if ( $app->config( 'PermCheckAtPreview' ) && $preview ) {
                if (! isset( $mt ) ) {
                    $app->access_forbidden();
                } else {
                    $client_author = $app->user();
                    if (! $client_author || $client_author->type == 2 ) {
                        $app->access_forbidden();
                    }
                }
            }
        }
        if (! $orig_mtime ) {
            $orig_mtime = filemtime( $file );
        }
        if ( $type_text && ( filesize( $file ) < $size_limit ) ) {
            $regex = '<\${0,1}' . 'mt';
            if (! $text ) {
                if ( $dynamicphpfirst ) {
                    ob_start();
                    include( $file );
                    $text = ob_get_contents();
                    ob_end_clean();
                } else {
                    $text = file_get_contents( $file );
                }
            }
            if (! $app->config( 'DynamicAllowPHPinTemplate' ) ) {
                $text = $app->strip_php( $text );
            }
            $stripphp_block = '<mt:{0,1}StripPHP(?:\s.*?)?>(.*?)<\/mt:{0,1}StripPHP\s*>';
            if ( preg_match_all( "/$stripphp_block/is", $text, $matchs ) ) {
                $blocks = $matchs[ 0 ];
                $inner_blocks = $matchs[ 1 ];
                $counter = 0;
                foreach ( $blocks as $match ) {
                    $block = preg_quote( $match, '/' );
                    $text = preg_replace( "/$block/", $app->strip_php( $inner_blocks[ $counter ] ), $text );
                    $counter++;
                }
            }
            $app->stash( 'text', $text );
            if ( isset( $mt ) && ( preg_match( "/$regex/i", $text ) ) ) {
                $last_ts = NULL;
                $file_ts = NULL;
                $file_ts = filemtime( $file );
                if (! $no_database ) {
                    $last_ts = $blog->blog_children_modified_on;
                    $last_ts = strtotime( $last_ts );
                    if ( $file_ts > $last_ts ) {
                        $last_ts = $file_ts;
                    }
                } else {
                    $last_ts = $file_ts;
                }
                if ( $conditional ) {
                    $app->do_conditional( $last_ts );
                }
                $orig_mtime = $last_ts;
                // Set Context
                $ctx->stash( 'dynamicmtml', 1 );
                $ctx->stash( 'blog', $blog );
                $ctx->stash( 'blog_id', $blog_id );
                $ctx->stash( 'local_blog_id', $blog_id );
                $filemtime = $orig_mtime;
                $build_type = 'dynamic_mtml';
                $app->stash( 'build_type', $build_type );
                if (! $no_database ) {
                    $app->run_callbacks( 'pre_resolve_url', $mt, $ctx, $args );
                    $data = $app->stash( 'fileinfo' );
                    if (! isset( $data ) ) {
                        $data = $mt->db()->resolve_url( $mt->db()->escape( urldecode( $request ) ),
                                                        $blog_id, array( 1, 2, 4 ) );
                    }
                    $app->stash( 'fileinfo', $data );
                    $app->run_callbacks( 'post_resolve_url', $mt, $ctx, $args );
                }
                $template = NULL;
                if ( $force_compile ) {
                    $ctx->force_compile = true;
                }
                if ( isset( $data ) ) {
                    $fi_path = $data->fileinfo_url;
                    $fid = $data->id;
                    $at = $data->archive_type;
                    $ts = $data->startdate;
                    $tpl_id = $data->template_id;
                    $cat = $data->category_id;
                    $auth = $data->author_id;
                    $entry_id = $data->entry_id;
                    if ( $at == 'index' ) {
                        $at = NULL;
                        $ctx->stash( 'index_archive', true );
                    } else {
                        $ctx->stash( 'index_archive', false );
                    }
                    if (! $tmpl = $ctx->stash( 'template' ) ) {
                        $tmpl = $data->template();
                        $ctx->stash( 'template', $tmpl );
                    }
                    $ctx->stash( 'template', $tmpl );
                    $tts = $tmpl->template_modified_on;
                    if ( $tts ) {
                        $tts = offset_time( datetime_to_timestamp( $tts ), $blog );
                    }
                    $ctx->stash( 'template_timestamp', $tts );
                    $ctx->stash( 'template_created_on', $tmpl->template_created_on );
                    $page_layout = $blog->blog_page_layout;
                    $columns = get_page_column( $page_layout );
                    $vars = $ctx->__stash[ 'vars' ];
                    $vars[ 'page_columns' ] = $columns;
                    $vars[ 'page_layout' ] = $page_layout;
                    if ( isset( $tmpl->template_identifier ) )
                        $vars[ $tmpl->template_identifier ] = 1;
                    $mt->configure_paths( $blog->site_path() );
                    $ctx->stash( 'build_template_id', $tpl_id );
                    if ( isset( $at ) && ( $at != 'Category' ) ) {
                        require_once( 'archive_lib.php' );
                        try {
                            $archiver = ArchiverFactory::get_archiver( $at );
                        } catch ( Exception $e ) {
                            $mt->http_errr = 404;
                            header( 'HTTP/1.1 404 Not Found' );
                            return $ctx->error(
                                $mt->translate( 'Page not found - [_1]', $at ), E_USER_ERROR );
                        }
                        $archiver->template_params( $ctx );
                    }
                    if ( $cat ) {
                        if (! $archive_category = $ctx->stash( 'category' ) ) {
                            $archive_category = $mt->db()->fetch_category( $cat );
                        }
                        $ctx->stash( 'category', $archive_category );
                        $ctx->stash( 'archive_category', $archive_category );
                    }
                    if ( $auth ) {
                        if (! $archive_author = $ctx->stash( 'author' ) ) {
                            $archive_author = $mt->db()->fetch_author( $auth );
                        }
                        $ctx->stash( 'author', $archive_author );
                        $ctx->stash( 'archive_author', $archive_author );
                    }
                    if ( isset( $at ) ) {
                        if ( ( $at != 'Category' ) && isset( $ts ) ) {
                            list( $ts_start, $ts_end ) = $archiver->get_range( $ts );
                            $ctx->stash( 'current_timestamp', $ts_start );
                            $ctx->stash( 'current_timestamp_end', $ts_end );
                        }
                        $ctx->stash( 'current_archive_type', $at );
                    }
                    if ( isset( $entry_id ) && ( $entry_id )
                        && ( $at == 'Individual' || $at == 'Page' ) ) {
                        if (! $entry = $ctx->stash( 'entry' ) ) {
                            if ( $at == 'Individual' ) {
                                $entry = $mt->db()->fetch_entry( $entry_id );
                            } elseif ( $at == 'Page' ) {
                                $entry = $mt->db()->fetch_page( $entry_id );
                            }
                        }
                        $ctx->stash( 'entry', $entry );
                        $ctx->stash( 'current_timestamp', $entry->entry_authored_on );
                    }
                    if ( $at == 'Category' ) {
                        $vars = $ctx->__stash[ 'vars' ];
                        $vars[ 'archive_class' ]    = "category-archive";
                        $vars[ 'category_archive' ] = 1;
                        $vars[ 'archive_template' ] = 1;
                        $vars[ 'archive_listing' ]  = 1;
                        $vars[ 'module_category_archives' ] = 1;
                    }
                    $basename = '_' . md5( $file ) . '_mtml_tpl_id_' . $tpl_id;
                    ${$basename} = $text;
                } else {
                    $ctx->stash( 'no_fileinfo', 1 );
                    $basename = '_' . md5( $file ) . '_';
                    ${$basename} = $text;
                }
                $app->stash( 'template', $template );
                $app->stash( 'basename', $basename );
                $app->run_callbacks( 'pre_build_page', $mt, $ctx, $args );
                if ( $force_compile ) {
                    $ctx->force_compile = TRUE;
                }
                $_Id = 'file:' . $file;
                $content = $mt->fetch( $_Id );
                $app->run_callbacks( 'build_page', $mt, $ctx, $args, $content );
                $app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                echo $content;
                $result_type = $build_type;
                $app->stash( 'result_type', $result_type );
                $app->run_callbacks( 'post_return', $mt, $ctx, $args, $content );
                if ( file_exists( $template ) ) {
                    if ( $clear_cache ) {
                        unlink ( $template );
                    }
                }
            } else {
                if ( $conditional ) {
                    $app->do_conditional( filemtime( $file ) );
                }
                $filemtime = $orig_mtime;
                $build_type = 'static_text';
                if ( isset( $mt ) ) {
                    $app->stash( 'build_type', $build_type );
                    $app->stash( 'filemtime', $filemtime );
                    $app->run_callbacks( 'pre_build_page', $mt, $ctx, $args );
                }
                $content = $text;
                // if ( preg_match( "/$regex/i", $text ) ) {
                // TODO:: Build without DB!
                // $context =& $app->context();
                // $template = $app->get_smarty_template( $context, NULL, $basename, $filemtime );
                // }
                if ( $include_static ) {
                    ob_start();
                    include( $file );
                    $content = ob_get_contents();
                    ob_end_clean();
                }
                if ( isset( $mt ) ) {
                    $app->run_callbacks( 'build_page', $mt, $ctx, $args, $content );
                } else {
                    $content = $app->non_dynamic_mtml( $content );
                    $app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                    echo $content;
                    exit();
                }
                $app->send_http_header( $contenttype, $filemtime, strlen( $content ) );
                echo $content;
                $result_type = $build_type;
                $app->stash( 'result_type', $result_type );
                $app->run_callbacks( 'post_return', $mt, $ctx, $args, $content );
            }
        } else {
            if ( $conditional ) {
                $app->do_conditional( filemtime( $file ) );
            }
            $build_type = 'binary_data';
            if ( $type_text ) {
                $build_type = 'large_text';
            }
            $filemtime = $orig_mtime;
            $app->stash( 'filemtime', $filemtime );
            $app->stash( 'build_type', $build_type );
            if ( isset( $mt ) ) {
                $app->run_callbacks( 'pre_build_page', $mt, $ctx, $args );
            }
            $app->send_http_header( $contenttype, $filemtime, filesize( $file ) );
            $content = NULL;
            if ( filesize( $file ) < $size_limit ) {
                $content = file_get_contents( $file );
                $app->run_callbacks( 'build_page', $mt, $ctx, $args, $content );
                echo $content;
            } else {
                $app->echo_file_get_contents( $file, $size_limit );
            }
            if (! isset( $mt ) ) {
                exit();
            } else {
                $app->stash( 'result_type', $build_type );
                $app->run_callbacks( 'post_return', $mt, $ctx, $args, $content );
            }
        }
    } else {
        if ( isset( $mt ) && (! $no_database ) ) {
            $build_type = 'mt_dynamic';
            $app->stash( 'build_type', $build_type );
            $app->stash( 'filemtime', NULL );
            $app->run_callbacks( 'pre_build_page', $mt, $ctx, $args );
            if ( $force_compile ) {
                $ctx->force_compile = TRUE;
            }
            if ( $dynamic_caching ) {
                $mt->caching( TRUE );
            }
            if ( $dynamic_conditional ) {
                $mt->conditional( TRUE );
            }
            ob_start();
            $mt->view();
            $content = ob_get_contents();
            ob_end_clean();
            $app->run_callbacks( 'build_page', $mt, $ctx, $args, $content );
            $app->send_http_header( $contenttype, $ctime, strlen( $content ) );
            echo $content;
            $result_type = $build_type;
            $app->stash( 'result_type', $result_type );
            $app->run_callbacks( 'post_return', $mt, $ctx, $args, $content );
        } else {
            $app->file_not_found();
        }
    }
    // ========================================
    // Save Cache
    // ========================================
    $filemtime = $orig_mtime;
    $app->stash( 'filemtime', $filemtime );
    if ( $use_cache && $cache && $content ) {
        if ( isset( $mt ) ) {
            $app->run_callbacks( 'pre_save_cache', $mt, $ctx, $args, $content );
        }
        if (! ( $fh = fopen( $cache, 'w' ) ) ) {
            return;
        }
        fwrite( $fh, $content, 128000 );
        fclose( $fh );
        touch( $cache, $ctime );
        $app->stash( 'cache_saved', 1 );
    }
    if ( $preview ) {
        if ( $file && ( file_exists( $file ) ) ) {
            if ( $app->config( 'DeleteFileAtPreview' ) ) {
                unlink( $file );
            }
        }
    }
    if ( isset( $mt ) ) {
        $app->run_callbacks( 'take_down', $mt, $ctx, $args, $content );
    } else {
        $app->run_callbacks( 'take_down_error' );
    // ========================================
    // 503 Service Unavailable
    // ========================================
        $app->service_unavailable();
    }
    exit();
?>