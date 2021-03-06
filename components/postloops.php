<?php
/**
 * PostLoops class
 *
 */
class bSuite_PostLoops
{
	//cache ttl
	var $ttl = 14401; // a prime number slightly longer than four hours

	// instances
	var $instances;

	// posts matched by various instances of the widget
	var $posts; // $posts[ $loop_id ] = $post_id

	// terms from the posts in each instance
	var $terms; // $tags[ $loop_id ][ $taxonomy ][ $term_id ] = $count

	var $thumbnail_size = 'nines-thumbnail-small'; // the default thumbnail size

	function bSuite_PostLoops()
	{
		$this->path_web = plugins_url( plugin_basename( dirname( __FILE__ )));

		add_action( 'init', array( $this, 'init' ));

		add_action( 'template_redirect' , array( $this, 'get_default_posts' ) , 0 );
	}

	function init()
	{
		if( function_exists( 'add_image_size' ))
		{
			add_image_size( 'nines-thumbnail-small' , 100 , 100 , TRUE );
			add_image_size( 'nines-thumbnail-wide' , 200 , 150 , TRUE );
		}

		$this->get_instances();

		add_action( 'admin_init', array(&$this, 'admin_init' ));
//		add_filter( 'posts_request' , array( &$this , 'posts_request' ));
	}

	function admin_init()
	{
		wp_register_script( 'postloop-editwidgets', $this->path_web . '/js/edit_widgets.js', array('jquery'), '2' );
		wp_enqueue_script( 'postloop-editwidgets' );

		add_action( 'admin_footer', array( &$this, 'footer_activatejs' ));
	}

	public function footer_activatejs(){
?>
		<script type="text/javascript">
			postloops_widgeteditor();
		</script>
<?php
	}

	function get_default_posts()
	{
		global $wp_query;

		// test the cache first
		// this is about 100 to 1000 times faster than the nested loops below
		$cachekey = md5( serialize( $wp_query->posts ));
		if( $cached = wp_cache_get( $cachekey , 'bcmsdefaultposts' ))
		{
			$this->posts[-1] = $cached['posts'];
			$this->terms[-1] = $cached['terms'];

			return;
		}

		// process each post to capture post IDs and terms
		foreach( $wp_query->posts as $post )
		{
			// get the matching post IDs for the $postloops object
			$this->posts[-1][] = $post->ID;
			
			// get the matching terms by taxonomy
			$terms = wp_get_object_terms( $post->ID, (array) get_object_taxonomies( $post->post_type ) );

			// get the term taxonomy IDs for the $postloops object
			foreach( $terms as $term )
			{
				if( ! isset( $this->terms[-1][ $term->taxonomy ] ) ) // initialize
					$this->terms[-1][ $term->taxonomy ] = array();

				if( ! isset( $this->terms[-1][ $term->taxonomy ][ $term->term_id ] )) // initialize
					$this->terms[-1][ $term->taxonomy ][ $term->term_id ] = 0;

				$this->terms[-1][ $term->taxonomy ][ $term->term_id ]++; // increment
			}
		}

		// set the cache if we get here
		wp_cache_set( 
			$cachekey , 
			array( 
				'posts' => $this->posts[-1] , 
				'terms' => $this->terms[-1] , 
				'time' => time()
			) , 
			'bcmsdefaultposts' , 
			$this->ttl 
		);
	}

	function get_instances()
	{
		$options = get_option( 'widget_postloop' );

		// add an entry for the default conent
		$options[-1] = array( 
			'title' => 'The default content',
		);

		foreach( $options as $number => $option )
		{
			if( is_integer( $number ))
			{
				$option['title'] = empty( $option['title'] ) ? 'Instance #'. $number : wp_filter_nohtml_kses( $option['title'] );
				$this->instances[ $number ] = $option;
			}
		}

		return $this->instances;
	}

	function get_instances_response()
	{
		$options = get_option( 'widget_responseloop' );

		// add an entry for the default conent
		$options[-1] = array( 
			'title' => 'The default content',
		);

		foreach( $options as $number => $option )
		{
			if( is_integer( $number ))
			{
				$option['title'] = empty( $option['title'] ) ? 'Instance #'. $number : wp_filter_nohtml_kses( $option['title'] );
				$this->instances_response[ md5( (string) $number . $option['template'] . $option['email'] ) ] = $option;
			}
		}

		return $this->instances_response;
	}

	function get_templates_readdir( $template_base )
	{
		$page_templates = array();
		$template_dir = @ dir( $template_base );
		if ( $template_dir ) 
		{
			while ( ( $file = $template_dir->read() ) !== false ) 
			{
				if ( preg_match('|^\.+$|', $file ))
					continue;
				if ( preg_match('|\.php$|', $file )) 
				{
					$template_data = implode( '', file( $template_base . $file ));
	
					$name = '';
					if ( preg_match( '|Template Name:(.*)$|mi', $template_data, $name ))
						$name = _cleanup_header_comment( $name[1] );

					$wrapper = FALSE;
					if ( preg_match( '|Wrapper:(.*)$|mi', $template_data )) // any value here will set it true
						$wrapper = TRUE;

					if ( !empty( $name ) ) 
					{
						$file = basename( $file );
						$page_templates[ $file ]['name'] = trim( $name );
						$page_templates[ $file ]['file'] = basename( $file );
						$page_templates[ $file ]['fullpath'] = $template_base . $file;
						$page_templates[ $file ]['wrapper'] = $wrapper;
					}
				}
			}
			@$template_dir->close();
		}

		return $page_templates;
	}
	
	function get_templates( $type = 'post' )
	{
		$type = sanitize_file_name( $type );
		$type_var = "templates_$type";

		if( isset( $this->$type_var ))
			return $this->$type_var;

		$this->$type_var = array_merge
		( 
			(array) $this->get_templates_readdir( dirname( dirname( __FILE__ )) .'/templates-'. $type .'/' ),
			(array) $this->get_templates_readdir( TEMPLATEPATH . '/templates-'. $type .'/' ), 
			(array) $this->get_templates_readdir( STYLESHEETPATH . '/templates-'. $type .'/' ) 
		);

		return $this->$type_var;
	}


	function _missing_template()
	{
?><!-- ERROR: the required template file is missing or unreadable. A default template is being used instead. -->
<div <?php post_class() ?> id="post-<?php the_ID(); ?>">
	<h2><a href="<?php the_permalink() ?>" rel="bookmark" title="Permanent Link to <?php the_title_attribute(); ?>"><?php the_title(); ?></a></h2>
	<small><?php the_time('F jS, Y') ?> <!-- by <?php the_author() ?> --></small>

	<div class="entry">
		<?php the_content('Read the rest of this entry &raquo;'); ?>
	</div>

	<p class="postmetadata"><?php the_tags('Tags: ', ', ', '<br />'); ?> Posted in <?php the_category(', ') ?> | <?php edit_post_link('Edit', '', ' | '); ?>  <?php comments_popup_link('No Comments &#187;', '1 Comment &#187;', '% Comments &#187;'); ?></p>
</div>
<?php
	}

	function do_template( $name , $event , $query_object = FALSE , $postloop_object = FALSE , $widget = FALSE )
	{

		// get the post templates
		$templates = $this->get_templates( 'post' );

		// check that we have a template by this name
		if( ! isset( $templates[ $name ] ))
		{
			$this->_missing_template();
			return;
		}

		// do it
		switch( $event )
		{
			case 'before':
				if( isset( $templates[ $name ]['wrapper'] ) && ( ! @include preg_replace( '/\.php$/', '_before.php', $templates[ $name ]['fullpath'] )))
					echo '<!-- ERROR: the required template wrapper file is missing or unreadable. -->';

				break;

			case 'after':
				if( isset( $templates[ $name ]['wrapper'] ) && ( ! @include preg_replace( '/\.php$/', '_after.php', $templates[ $name ]['fullpath'] )))
					echo '<!-- ERROR: the required template wrapper file is missing or unreadable. -->';

				break;

			default:
				if( ! @include $templates[ $name ]['fullpath'] )
					$this->_missing_template();

		}
	}

	function get_actions( $type = 'post' )
	{
		$templates = $this->get_templates( $type );

		$actions = array();

		foreach( $templates as $template => $info )
		{
			$actions[ $template ] = array( 
				'name' 		=> $info['name'],
				'callback' 	=> array( $this , 'do_template' ),
			);
		}

		return apply_filters( 'postloop_actions' , $actions );
	}

	function do_action( $type , $name , $event , $query_object , $widget )
	{

		$this->current->widget = $widget;
		$this->current->query = $query_object;

		$actions = $this->get_actions( $type );

		if( isset( $actions[ $name ] ) && is_callable( $actions[ $name ]['callback'] ))
			call_user_func( $actions[ $name ]['callback'] , $name , $event , $query_object , $this  , $widget );
	}

	function posts_where_comments_yes_once( $sql )
	{
		remove_filter( 'posts_where', array( &$this , 'posts_where_comments_yes_once' ), 10 );
		return $sql . ' AND comment_count > 0 ';
	}
	function posts_where_comments_no_once( $sql )
	{
		remove_filter( 'posts_where', array( &$this , 'posts_where_comments_no_once' ), 10 );
		return $sql . ' AND comment_count < 1 ';
	}

	function posts_where_date_since_once( $sql )
	{
		remove_filter( 'posts_where', array( &$this , 'posts_where_date_since_once' ), 10 );
		return $sql . ' AND post_date > "'. $this->date_since .'"';
	}

	function posts_where_date_before_once( $sql )
	{
		remove_filter( 'posts_where', array( &$this , 'posts_where_date_since_once' ), 10 );
		return $sql . ' AND post_date < "'. $this->date_before .'"';
	}

	function posts_join_recently_popular_once( $sql )
	{
		global $wpdb, $blog_id, $bsuite;

		remove_filter( 'posts_join', array( &$this , 'posts_join_recently_popular_once' ), 10 );
		return " INNER JOIN $bsuite->hits_pop AS popsort ON ( popsort.blog_id = $blog_id AND popsort.hits_recent > 0 AND $wpdb->posts.ID = popsort.post_ID ) ". $sql;
	}

	function posts_orderby_recently_popular_once( $sql )
	{
		remove_filter( 'posts_orderby', array( &$this , 'posts_orderby_recently_popular_once' ), 10 );
		return ' popsort.hits_recent DESC, '. $sql;
	}

	function posts_fields_recently_commented_once( $sql )
	{
		remove_filter( 'posts_fields', array( &$this , 'posts_fields_recently_commented_once' ), 10 );
		return $sql. ', MAX( commentsort.comment_date_gmt ) AS commentsort_order ';
	}

	function posts_join_recently_commented_once( $sql )
	{
		global $wpdb;

		remove_filter( 'posts_join', array( &$this , 'posts_join_recently_commented_once' ), 10 );
		return " INNER JOIN $wpdb->comments AS commentsort ON ( commentsort.comment_approved = 1 AND $wpdb->posts.ID = commentsort.comment_post_ID ) ". $sql;
	}

	function posts_groupby_recently_commented_once( $sql )
	{
		global $wpdb;

		remove_filter( 'posts_groupby', array( &$this , 'posts_groupby_recently_commented_once' ), 10 );
		return $wpdb->posts .'.ID' . ( empty( $sql ) ? '' : ', ' );
	}

	function posts_orderby_recently_commented_once( $sql )
	{
		remove_filter( 'posts_orderby', array( &$this , 'posts_orderby_recently_commented_once' ), 10 );
		return ' commentsort_order DESC, '. $sql;
	}

	function posts_request( $request )
	{
		echo $request;
		return $request;
	}

} //end bSuite_PostLoops

// initialize that class
global $postloops;
$postloops = new bSuite_PostLoops();


/**
 * PostLoop Scroller class
 *
 */
class bCMS_PostLoop_Scroller
{
	function __construct( $args = '' )
	{

		// get settings
		$defaults = array(
			// configuration
			'actionname' => 'postloop_f_default_scroller',
			'selector' => '.scrollable',
			'lazy' => FALSE,
			'css' => TRUE,

			// scrollable options
			'keyboard' => TRUE, // FALSE or 'static'
			'circular' => TRUE,
			'vertical' => FALSE,
			'mousewheel' => FALSE,

			// scrollable plugins
			'navigator' => TRUE,  // FALSE or selector (html id or classname)
			'autoscroll' => array(
				'interval' => 2500,
				'autoplay' => TRUE,
				'autopause' => TRUE,
				'steps' => 1,
			)
		);
		$this->settings = (object) wp_parse_args( (array) $args , (array) $defaults );

		// get the path to our scripts and styles
		$this->path_web = plugins_url( plugin_basename( dirname( __FILE__ )));

		// register scripts and styles
		wp_register_script( 'scrollable', $this->path_web . '/js/scrollable.min.js', array('jquery'), TRUE );
		bcms_late_enqueue_script( 'scrollable' );
		add_filter( 'print_footer_scripts', array( $this, 'print_js' ), 10, 1 );

		if( $this->settings->css )
		{
			wp_register_style( 'scrollable', $this->path_web .'/css/scrollable.css' );
			bcms_late_enqueue_style( 'scrollable' );
		}
	}

	function print_js( $finish_print )
	{
?>
<script type="text/javascript">	
	;(function($){
		$(window).load(function(){
			var $child = $('<?php echo $this->settings->child_selector; ?>');
			var $parent = $('<?php echo $this->settings->parent_selector; ?>');

			// set the size of some items
			$child.width( $parent.width() );
			$parent.height( $child.height() );

			// initialize scrollable
			$parent
				.scrollable({ circular: true })
				.navigator()
				.autoscroll(<?php echo json_encode( $this->settings->autoscroll ); ?>);

			//show the .items divs now that the scrollable is initialized
			$child.width( $(window).width() ).show();
		});
	})(jQuery);
</script>
<?php
		return $finish_print;
	}
}


/**
 * PostLoop widget class
 *
 */
class bSuite_Widget_PostLoop extends WP_Widget
{

	var $ttl = 3607; // a prime number slightly longer than one hour


	function bSuite_Widget_PostLoop()
	{

		$widget_ops = array('classname' => 'widget_postloop', 'description' => __( 'Build your own post loop') );
		$this->WP_Widget('postloop', __('Post Loop'), $widget_ops);

		add_filter( 'wijax-actions' , array( $this , 'wjiax_actions' ) );
	}

	function wjiax_actions( $actions )
	{
		global $postloops, $mywijax;
		foreach( $postloops->instances as $k => $v )
			$actions[ $mywijax->encoded_name( 'postloop-'. $k ) ] = (object) array( 'key' => 'postloop-'. $k , 'type' => 'widget');

		return $actions;
	}

	function widget( $args, $instance )
	{
		global $bsuite, $postloops, $wpdb, $mywijax;

		$cached = (object) array();

		$this->wijax_varname = $mywijax->encoded_name( $this->id );

		extract( $args );

		$title = apply_filters('widget_title', empty( $instance['title'] ) ? '' : $instance['title']);

		if( 'normal' == $instance['query'] || 'normal' == $instance['what'] )
		{
			wp_reset_query();
			global $wp_query;

			$ourposts = &$wp_query;

		}// end if
		else if( preg_match( '/^predefined_/' , $instance['query'] ))
		{
			// get the predefined query object
			$ourposts = apply_filters( 'postloop_query_'. preg_replace( '/^predefined_/' , '' , $instance['query'] ) , FALSE );

			// check that we got something
			if( ! $ourposts || ! is_object( $ourposts ))
			{
				echo '<!-- error: the predefined query is invalid -->';
				return FALSE;
			}
		}
		else
		{
//			$criteria['suppress_filters'] = TRUE;

			$criteria['post_type'] = array_values( array_intersect( (array) $this->get_post_types() , (array) $instance['what'] ));

			if( in_array( $instance['what'], array( 'attachment', 'revision' )))
				$criteria['post_status'] = 'inherit';

			if( !empty( $instance['categories_in'] ))
				$criteria['category__'. ( in_array( $instance['categoriesbool'], array( 'in', 'and', 'not_in' )) ? $instance['categoriesbool'] : 'in' ) ] = array_keys( (array) $instance['categories_in'] );

			if( $instance['categories_in_related'] )
				$criteria['category__'. ( in_array( $instance['categoriesbool'], array( 'in', 'and', 'not_in' )) ? $instance['categoriesbool'] : 'in' ) ] = array_merge( (array) $criteria['category__'. ( in_array( $instance['categoriesbool'], array( 'in', 'and', 'not_in' )) ? $instance['categoriesbool'] : 'in' ) ], (array) array_keys( (array) $postloops->terms[ $instance['categories_in_related'] ]['category'] ) );

			if( !empty( $instance['categories_not_in'] ))
				$criteria['category__not_in'] = array_keys( (array) $instance['categories_not_in'] );

			if( $instance['categories_not_in_related'] )
				$criteria['category__not_in'] = array_merge( (array) $criteria['category__not_in'] , (array) array_keys( (array) $postloops->terms[ $instance['categories_not_in_related'] ]['category'] ));

			if( !empty( $instance['tags_in'] ))
				$criteria['tag__'. ( in_array( $instance['tagsbool'], array( 'in', 'and', 'not_in' )) ? $instance['tagsbool'] : 'in' ) ] = $instance['tags_in'];

			if( $instance['tags_in_related'] )
				$criteria['tag__'. ( in_array( $instance['tagsbool'], array( 'in', 'and', 'not_in' )) ? $instance['tagsbool'] : 'in' ) ] = array_merge( (array) $criteria['tag__'. ( in_array( $instance['tagsbool'], array( 'in', 'and', 'not_in' )) ? $instance['tagsbool'] : 'in' ) ], (array) array_keys( (array) $postloops->terms[ $instance['tags_in_related'] ]['post_tag'] ) );

			if( !empty( $instance['tags_not_in'] ))
				$criteria['tag__not_in'] = $instance['tags_not_in'];

			if( $instance['tags_not_in_related'] )
				$criteria['tag__not_in'] = array_merge( (array) $criteria['tag__not_in'] , (array) array_keys( (array) $postloops->terms[ $instance['tags_not_in_related'] ]['post_tag'] ));

			$tax_query = array();

/*
			if( $instance['tags_in_related'] )
				$instance['tags_in'] = array_merge( 
					(array) $instance['tags_in'] ,
					(array) array_keys( (array) $postloops->terms['post_tag'][ $taxonomy ] )
				);

			if( count( $instance['tags_in'] ))
			{
				$tax_query[] = array(
					'taxonomy' => 'post_tag',
					'field' => 'term_id',
					'terms' => $instance['tags_in'],
					'operator' => strtoupper( $instance['tagsbool'] ),
				);
			}

			if( $instance['tags_not_in_related'] )
				$instance['tags_not_in'] = array_merge( 
					(array) $instance['tags_not_in'] , 
					(array) array_keys( (array) $postloops->terms['post_tag'][ $taxonomy ] )
				);

			if( count( $instance['tags_not_in'] ))
			{
				$tax_query[] = array(
					'taxonomy' => 'post_tag',
					'field' => 'term_id',
					'terms' => $instance['tags_not_in'],
					'operator' => 'NOT IN',
				);
			}
*/
			foreach( get_object_taxonomies( $criteria['post_type'] ) as $taxonomy )
			{
				if( $taxonomy == 'category' || $taxonomy == 'post_tag' )
					continue;

				if( $instance['tax_'. $taxonomy .'_in_related'] )
					$instance['tax_'. $taxonomy .'_in'] = array_merge( 
						(array) $instance['tax_'. $taxonomy .'_in'] ,
						(array) array_keys( (array) $postloops->terms[ $instance['tax_'. $taxonomy .'_in_related'] ][ $taxonomy ] )
					);

				if( count( $instance['tax_'. $taxonomy .'_in'] ))
				{
					$tax_query[] = array(
						'taxonomy' => $taxonomy,
						'field' => 'term_id',
						'terms' => $instance['tax_'. $taxonomy .'_in'],
						'operator' => strtoupper( $instance['tax_'. $taxonomy .'_bool'] ),
					);
				}

				if( $instance['tax_'. $taxonomy .'_not_in_related'] )
					$instance['tax_'. $taxonomy .'_not_in'] = array_merge( 
						(array) $instance['tax_'. $taxonomy .'_not_in'] , 
						(array) array_keys( (array) $postloops->terms[ $instance['tax_'. $taxonomy .'_not_in_related'] ][ $taxonomy ] )
					);

				if( count( $instance['tax_'. $taxonomy .'_not_in'] ))
				{
					$tax_query[] = array(
						'taxonomy' => $taxonomy,
						'field' => 'term_id',
						'terms' => $instance['tax_'. $taxonomy .'_not_in'],
						'operator' => 'NOT IN',
					);
				}
			}
			if( count( $tax_query ))
				$criteria['tax_query'] = $tax_query;

			if( !empty( $instance['post__in'] ))
				$criteria['post__in'] = $instance['post__in'];
	
			if( !empty( $instance['post__not_in'] ))
				$criteria['post__not_in'] = $instance['post__not_in'];

			switch( $instance['comments'] )
			{
				case 'yes':
					add_filter( 'posts_where', array( &$postloops , 'posts_where_comments_yes_once' ), 10 );
					break;
				case 'no':
					add_filter( 'posts_where', array( &$postloops , 'posts_where_comments_no_once' ), 10 );
					break;
				default:
					break;
			}

			if( 0 < $instance['age_num'] )
			{
				$postloops->date_before = $postloops->date_since = date( 'Y-m-d' , strtotime( $instance['age_num'] .' '. $instance['age_unit'] .' ago' ));
				if( $instance['age_bool'] == 'older' )
					add_filter( 'posts_where', array( &$postloops , 'posts_where_date_before_once' ), 10 );
				else
					add_filter( 'posts_where', array( &$postloops , 'posts_where_date_since_once' ), 10 );
			}

			if( isset( $_GET['wijax'] ) && absint( $_GET['paged'] ))
				$criteria['paged'] = absint( $_GET['paged'] );
			$criteria['showposts'] = absint( $instance['count'] );

			switch( $instance['order'] ){
				case 'age_new':
					$criteria['orderby'] = 'date';
					$criteria['order'] = 'DESC';
					break;

				case 'age_old':
					$criteria['orderby'] = 'date';
					$criteria['order'] = 'ASC';
					break;

				case 'title_az':
					$criteria['orderby'] = 'title';
					$criteria['order'] = 'ASC';
					break;

				case 'title_za':
					$criteria['orderby'] = 'title';
					$criteria['order'] = 'DESC';
					break;

				case 'comment_new':
					add_filter( 'posts_fields',		array( &$postloops , 'posts_fields_recently_commented_once' ), 10 );
					add_filter( 'posts_join',		array( &$postloops , 'posts_join_recently_commented_once' ), 10 );
					add_filter( 'posts_groupby',	array( &$postloops , 'posts_groupby_recently_commented_once' ), 10 );
					add_filter( 'posts_orderby',	array( &$postloops , 'posts_orderby_recently_commented_once' ), 10 );
					break;

				case 'comment_count':
					$criteria['orderby'] = 'comment_count';
					$criteria['order'] = 'DESC';
					break;

				case 'pop_recent':
					if( is_object( $bsuite ))
					{
						add_filter( 'posts_join',		array( &$postloops , 'posts_join_recently_popular_once' ), 10 );
						add_filter( 'posts_orderby',	array( &$postloops , 'posts_orderby_recently_popular_once' ), 10 );
						break;
					}

				case 'rand':
					$criteria['orderby'] = 'rand';
					break;

				default:
					$criteria['orderby'] = 'post_date';
					$criteria['order'] = 'DESC';
					break;
			}

			if( 'excluding' == $instance['relationship'] && count( (array) $instance['relatedto'] ))
			{
				foreach( $instance['relatedto'] as $related_loop => $temp )
				{
					if( isset( $postloops->posts[ $related_loop ] ))
						$criteria['post__not_in'] = array_merge( (array) $criteria['post__not_in'] , $postloops->posts[ $related_loop ] );
					else
						echo '<!-- error: related post loop is not available -->';
				}
			}
			else if( 'similar' == $instance['relationship'] && count( (array) $instance['relatedto'] ))
			{
				if( ! class_exists( 'bSuite_bSuggestive' ) )
					require_once( dirname( __FILE__) .'/bsuggestive.php' );

				foreach( $instance['relatedto'] as $related_loop => $temp )
				{
					if( isset( $postloops->posts[ $related_loop ] ))
						$posts_for_related = array_merge( (array) $posts_for_related , $postloops->posts[ $related_loop ] );
					else
						echo '<!-- error: related post loop is not available -->';
				}

				$count = ceil( 1.5 * $instance['count'] );
				if( 10 > $count )
					$count = 10;

				$criteria['post__in'] = array_merge( 
					(array) $instance['post__in'] , 
					array_slice( (array) bSuite_bSuggestive::getposts( $posts_for_related ) , 0 , $count )
				);
			}

			//echo '<pre>'. print_r( $postloops , TRUE ) .'</pre>';
			//echo '<pre>'. print_r( $instance , TRUE ) .'</pre>';

			// allow filtering of the criteria
			$criteria = apply_filters( 'postloop_criteria' , $criteria , $instance );

			// print the post selection info for logged-in administrators
			if( is_user_logged_in() && current_user_can( 'edit_theme_options' ))
				echo "<!-- postloop criteria \n". esc_html( print_r( $criteria , TRUE )) .' -->';

			// check the cache for posts
			// we only check the cache for custom post loops,
			// as the default loop is already queried and nobody wants to waste the effort
			$cachekey = md5( serialize( $criteria ) . serialize( $instance ) . 'q' );
			if( ! $cached = wp_cache_get( $cachekey , 'bcmspostloop' ))
			{
				// no cache exists, executing the query
				$ourposts = new WP_Query( $criteria );

				echo "\n<!-- postloop generated fresh on ". date(DATE_RFC822) .' -->';

				//echo '<pre>'. print_r( $ourposts , TRUE ) .'</pre>';

				// print the wp_query object for logged-in administrators
				if( is_user_logged_in() && current_user_can( 'edit_theme_options' ))
				{
					$debug_copy = clone $ourposts;
					unset( $debug_copy->post );
					foreach( $debug_copy->posts as $k => $v )
					{
						$debug_copy->posts[ $k ] = (object) array( 
							'ID' => $v->ID , 
							'post_date' => $v->post_date , 
							'post_title' => $v->post_title 
						);
					}

					echo "<!-- postloop wp_query obj (excludes posts) \n". esc_html( print_r( $debug_copy , TRUE )) .' -->';
				}
			}
			else
			{
				echo '<!-- postloop fetched from cache, generated on '. date( DATE_RFC822 , $cached->time ) .' -->';
			}

		}

		if( ! isset( $cached->html ) && $ourposts->have_posts() )
		{

			// get the templates, thumbnail size, and other stuff
			$this->post_templates = (array) $postloops->get_templates('post');
			$cached->template = $this->post_templates[ $instance['template'] ];

			$postloops->current_postloop = &$instance;

			$postloops->thumbnail_size = isset( $instance['thumbnail_size'] ) ? $instance['thumbnail_size'] : 'nines-thumbnail-small';

			// open an output buffer and start processing the loop
			ob_start();

			$offset_run = $offset_now = 1;

			// old actions
			$action_name = 'postloop_'. sanitize_title( basename( $instance['template'] , '.php' ));
			do_action( $action_name , 'before' , $ourposts , $postloops );

			// new actions
			$postloops->do_action( 'post' , $instance['template'], 'before' , $ourposts , $this );

			while( $ourposts->have_posts() )
			{
				unset( $GLOBALS['pages'] ); // to address ticket: http://core.trac.wordpress.org/ticket/12651
				
				$ourposts->the_post();

				// weird feature to separate a single postloop into multiple widgets
				// set where in the loop we start the output
				if( ! empty( $instance['offset_start'] ) && ($instance['offset_start'] > $offset_now) )
				{
					$offset_now ++;
					continue;
				}
				// set how many we display
				if( ! empty( $instance['offset_run'] ) && ($instance['offset_run'] < $offset_run) )
				{
					continue;
				}
				
				$offset_run ++;

				global $id, $post;

				// get the matching post IDs for the $postloops object
				$postloops->posts[ $this->number ][] = $id;

				// get the matching terms by taxonomy
				$terms = get_object_term_cache( $id, (array) get_object_taxonomies( $post->post_type ) );
				if ( empty( $terms ))
					$terms = wp_get_object_terms( $id, (array) get_object_taxonomies( $post->post_type ) );

				// get the term taxonomy IDs for the $postloops object
				foreach( $terms as $term )
				{
					if ( ! isset( $postloops->terms[ $this->number ] ) )
					{
						$postloops->terms[ $this->number ] = array( $term->taxonomy =>  array( $term->term_id =>0 ) );
					}
					elseif ( ! isset( $postloops->terms[ $this->number ][ $term->taxonomy ] ) )
					{
						$postloops->terms[ $this->number ][ $term->taxonomy ] = array( $term->term_id =>0 );
					}
					elseif ( ! isset( $postloops->terms[ $this->number ][ $term->taxonomy ][ $term->term_id ] ) )
					{
						$postloops->terms[ $this->number ][ $term->taxonomy ][ $term->term_id ] = 0;	
					}
						
					$postloops->terms[ $this->number ][ $term->taxonomy ][ $term->term_id ]++;
				}

				// old actions
				do_action( $action_name , 'post' , $ourposts , $postloops );

				// new actions
				$postloops->do_action( 'post' , $instance['template'] , '' , $ourposts , $this );

			}

			// old actions
			do_action( $action_name , 'after' , $ourposts , $postloops );

			// new actions
			$postloops->do_action( 'post' , $instance['template'] , 'after' , $ourposts , $this );

			$cached->html = ob_get_clean();
			// end process the loop
		}

		if( isset( $cached->html ))
		{
			if( isset( $cached->instance ))
				$instance = $cached->instance;

			// figure out what classes to put on the widget
			$extra_classes = array();
			$extra_classes[] = str_replace( '9spot', 'nines' , sanitize_title_with_dashes( $cached->template['name'] ));
			$extra_classes[] = 'widget-post_loop-'. sanitize_title_with_dashes( $instance['title'] );
			$instance['extra_classes'] = isset( $instance['extra_classes'] ) ? (array) $instance['extra_classes'] : array();
			$extra_classes = array_merge( $extra_classes , $instance['extra_classes'] );


			// output the widget
			echo str_replace( 'class="', 'class="'. implode( ' ' , $extra_classes ) .' ' , $before_widget );
			$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? '' : $instance['title'] );
			if ( $instance['title_show'] && $title )
				echo $before_title . $title . $after_title .'<div class="widget_subtitle">'. $instance['subtitle'] .'</div>';
	
			echo $cached->html . $after_widget;

			if( isset( $cachekey ))
				wp_cache_set( $cachekey , (object) array( 'html' => $cached->html , 'template' => $cached->template , 'instance' => $instance , 'time' => time() ) , 'bcmspostloop' , $this->ttl );
		}

		unset( $postloops->current_postloop );

//print_r( $postloops );
	}

	function update( $new_instance, $old_instance ) {

		$instance = $old_instance;

		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['subtitle'] = wp_filter_nohtml_kses( $new_instance['subtitle'] );
		$instance['title_show'] = absint( $new_instance['title_show'] );

		$allowed_queries = array( 'normal' , 'custom' );
		$predefined_queries = apply_filters( 'postloop_predefined_queries' , array());
		foreach( $predefined_queries as $k => $v )
		{
			$k = preg_replace( '/[^a-zA-Z0-9_-]*/' , '' , $k );
			$allowed_queries[] = 'predefined_'. $k;
		}
		$instance['query'] = in_array( $new_instance['query'] , $allowed_queries ) ? $new_instance['query'] : 'normal';

		$instance['what'] = (array) array_intersect( (array) $this->get_post_types() , array_keys( $new_instance['what'] ));


		$instance['categoriesbool'] = in_array( $new_instance['categoriesbool'], array( 'in', 'and', 'not_in') ) ? $new_instance['categoriesbool']: '';
		$instance['categories_in'] = array_filter( array_map( 'absint', $new_instance['categories_in'] ));
		$instance['categories_in_related'] = (int) $new_instance['categories_in_related'];
		$instance['categories_not_in'] = array_filter( array_map( 'absint', $new_instance['categories_not_in'] ));
		$instance['categories_not_in_related'] = (int) $new_instance['categories_not_in_related'];
		$instance['tagsbool'] = in_array( $new_instance['tagsbool'], array( 'in', 'and', 'not_in') ) ? $new_instance['tagsbool']: '';
		$tag_name = '';
		$instance['tags_in'] = array();
		foreach( array_filter( array_map( 'trim', array_map( 'wp_filter_nohtml_kses', explode( ',', $new_instance['tags_in'] )))) as $tag_name )
		{
			if( $temp = is_term( $tag_name, 'post_tag' ))
				$instance['tags_in'][] = $temp['term_id'];
		}
		$instance['tags_in_related'] = (int) $new_instance['tags_in_related'];
		$tag_name = '';
		$instance['tags_not_in'] = array();
		foreach( array_filter( array_map( 'trim', array_map( 'wp_filter_nohtml_kses', explode( ',', $new_instance['tags_not_in'] )))) as $tag_name )
		{
			if( $temp = is_term( $tag_name, 'post_tag' ))
				$instance['tags_not_in'][] = $temp['term_id'];
		}
		$instance['tags_not_in_related'] = (int) $new_instance['tags_not_in_related'];

		if( $instance['what'] <> 'normal' )
		{
			foreach( get_object_taxonomies( $instance['what'] ) as $taxonomy )
			{
				if( $taxonomy == 'category' || $taxonomy == 'post_tag' )
					continue;

				$instance['tax_'. $taxonomy .'_bool'] = in_array( $new_instance['tax_'. $taxonomy .'_bool'], array( 'in', 'and', 'not_in') ) ? $new_instance['tax_'. $taxonomy .'_bool']: '';
				$tag_name = '';
				$instance['tax_'. $taxonomy .'_in'] = array();
				foreach( array_filter( array_map( 'trim', array_map( 'wp_filter_nohtml_kses', explode( ',', $new_instance['tax_'. $taxonomy .'_in'] )))) as $tag_name )
				{
					if( $temp = is_term( $tag_name, $taxonomy ))
						$instance['tax_'. $taxonomy .'_in'][] = $temp['term_id'];
				}

				$instance['tax_'. $taxonomy .'_in_related'] = (int) $new_instance['tax_'. $taxonomy .'_in_related'];

				$tag_name = '';
				$instance['tax_'. $taxonomy .'_not_in'] = array();
				foreach( array_filter( array_map( 'trim', array_map( 'wp_filter_nohtml_kses', explode( ',', $new_instance['tax_'. $taxonomy .'_not_in'] )))) as $tag_name )
				{
					if( $temp = is_term( $tag_name, $taxonomy ))
						$instance['tax_'. $taxonomy .'_not_in'][] = $temp['term_id'];
				}

				$instance['tax_'. $taxonomy .'_not_in_related'] = (int) $new_instance['tax_'. $taxonomy .'_not_in_related'];
			}
		}

		$instance['post__in'] = array_filter( array_map( 'absint', explode( ',', $new_instance['post__in'] )));
		$instance['post__not_in'] = array_filter( array_map( 'absint', explode( ',', $new_instance['post__not_in'] )));
		$instance['comments'] = in_array( $new_instance['comments'], array( 'unset', 'yes', 'no' ) ) ? $new_instance['comments']: '';

		$instance['activity'] = in_array( $new_instance['activity'], array( 'pop_most', 'pop_least', 'pop_recent', 'comment_recent', 'comment_few') ) ? $new_instance['activity']: '';
		$instance['age_bool'] = in_array( $new_instance['age_bool'], array( 'newer', 'older') ) ? $new_instance['age_bool']: '';
		$instance['age_num'] = absint( $new_instance['age_num'] );
		$instance['age_unit'] = in_array( $new_instance['age_unit'], array( 'day', 'month', 'year') ) ? $new_instance['age_unit']: '';
		$instance['agestrtotime'] = strtotime( $new_instance['agestrtotime'] ) ? $new_instance['agestrtotime'] : '';
		$instance['relationship'] = in_array( $new_instance['relationship'], array( 'similar', 'excluding') ) ? $new_instance['relationship']: '';
		$instance['relatedto'] = array_filter( (array) array_map( 'intval', (array) $new_instance['relatedto'] ));
		$instance['count'] = absint( $new_instance['count'] );
		$instance['order'] = in_array( $new_instance['order'], array( 'age_new', 'age_old', 'title_az', 'title_za', 'comment_new', 'comment_count', 'pop_recent', 'rand' ) ) ? $new_instance['order']: '';
		$instance['template'] = wp_filter_nohtml_kses( $new_instance['template'] );
		$instance['offset_run'] = empty( $new_instance['offset_run'] ) ? '' : absint( $new_instance['offset_run'] );
		$instance['offset_start'] = empty( $new_instance['offset_start'] ) ? '' : absint( $new_instance['offset_start'] );
in_array( $new_instance['thumbnail_size'], (array) get_intermediate_image_sizes() ) ? $new_instance['thumbnail_size']: '';
		if( function_exists( 'get_intermediate_image_sizes' ))
			$instance['thumbnail_size'] = in_array( $new_instance['thumbnail_size'], (array) get_intermediate_image_sizes() ) ? $new_instance['thumbnail_size']: '';
		$instance['columns'] = absint( $new_instance['columns'] );

		$this->justupdated = TRUE;

/*
var_dump( $new_instance['categories_in_related'] );
var_dump( $instance['categories_in_related'] );
die;
*/
		return $instance;
	}

	function form( $instance ) {
		global $postloops, $bsuite;

		// reset the instances var, in case a new widget was added
		$postloops->get_instances();

		//Defaults

		$instance = wp_parse_args( (array) $instance, 
			array( 
				'what' => 'normal', 
				'template' => 'a_default_full.php',
				'title' => '',
				'subtitle' => '',
				'title_show' => FALSE,
				'query' => '',
				'offset_start' => 0,
				'categoriesbool' => FALSE,
				'tagsbool' => FALSE,
				'tags_in' => array(),
				'tags_in_related' => 0,
				'tags_not_in' => array(),
				'tags_not_in_related' => 0,
				'post__in' => array(),
				'post__not_in' => array(),
				'comments' => '',
				'age_bool' => FALSE,
				'age_num' => '',
				'age_unit' => 0,
				'relatedto' => '',
				'relationship' => 0,
				'count' => 0,
				'order' => 0,
				'offset_run' => 0,
				'offset_start' => 0,
				'thumbnail_size' => '',
				)
			);

		$title = esc_attr( $instance['title'] );
		$subtitle = esc_attr( $instance['subtitle'] );
		?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
			<label for="<?php echo $this->get_field_id( 'title_show' ) ?>"><input id="<?php echo $this->get_field_id( 'title_show' ) ?>" name="<?php echo $this->get_field_name( 'title_show' ) ?>" type="checkbox" value="1" <?php echo ( $instance[ 'title_show' ] ? 'checked="checked"' : '' ) ?>/> Show Title?</label>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('subtitle'); ?>"><?php _e('Sub-title'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('subtitle'); ?>" name="<?php echo $this->get_field_name('subtitle'); ?>" type="text" value="<?php echo $subtitle; ?>" />
		</p>

		<!-- Query type -->
		<div id="<?php echo $this->get_field_id('query'); ?>-container" class="postloop container querytype_normal posttype_normal">
			<label for="<?php echo $this->get_field_id('query'); ?>"><?php _e( 'What to show' ); ?></label>
			<div id="<?php echo $this->get_field_id('query'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('query'); ?>" id="<?php echo $this->get_field_id('query'); ?>" class="widefat postloop querytype_selector">
						<option value="normal" <?php selected( $instance['query'], 'normal' ); ?>><?php _e('The default content'); ?></option>
						<option value="custom" <?php selected( $instance['query'], 'custom' ); ?>><?php _e('Custom content'); ?></option>
						<?php
							$predefined_queries = apply_filters( 'postloop_predefined_queries' , array());
							foreach( $predefined_queries as $k => $v )
							{
								//sanitize the value name
								$k = preg_replace( '/[^a-zA-Z0-9_-]*/' , '' , $k );

								// output the option line
								?><option value="predefined_<?php echo $k; ?>" <?php selected( $instance['query'], 'predefined_'. $k ); ?>><?php esc_attr_e( $v ); ?></option><?php
							}
						?>
					</select>
				</p>
			</div>
		</div>

		<!-- Post type -->
		<div id="<?php echo $this->get_field_id('what'); ?>-container" class="postloop container querytype_custom posttype_normal">
			<label for="<?php echo $this->get_field_id('what'); ?>"><?php _e( 'Selecting what kind of content' ); ?></label>
			<div id="<?php echo $this->get_field_id('what'); ?>-contents" class="contents hide-if-js">
				<p>
					<ul>
						<?php foreach( (array) $this->get_post_types() as $type ) : $type = get_post_type_object( $type ); ?>
							<li><label for="<?php echo $this->get_field_id( 'what-'. esc_attr( $type->name )); ?>"><input id="<?php echo $this->get_field_id( 'what-'. esc_attr( $type->name )); ?>" name="<?php echo $this->get_field_name( 'what' ) .'['. esc_attr( $type->name ) .']'; ?>" type="checkbox" value="1" <?php echo ( isset( $instance[ 'what' ][ $type->name ] ) ? 'checked="checked" class="" ' : 'class="checkbox"' ); ?>/> <?php echo $type->labels->name; ?></label></li>
						<?php endforeach; ?>

					</ul>
				</p>
			</div>
		</div>

		<div id="<?php echo $this->get_field_id('categories'); ?>-container" class="postloop container hide-if-js <?php echo $this->tax_posttype_classes('category'); ?>">
			<label for="<?php echo $this->get_field_id('categoriesbool'); ?>"><?php _e( 'Categories' ); ?></label>
			<div id="<?php echo $this->get_field_id('categories'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('categoriesbool'); ?>" id="<?php echo $this->get_field_id('categoriesbool'); ?>" class="widefat">
						<option value="in" <?php selected( $instance['categoriesbool'], 'in' ); ?>><?php _e('Any of these categories'); ?></option>
						<option value="and" <?php selected( $instance['categoriesbool'], 'and' ); ?>><?php _e('All of these categories'); ?></option>
					</select>
					<ul><?php echo $this->control_categories( $instance , 'categories_in' ); ?></ul>
				</p>
		
				<p>
					<label for="<?php echo $this->get_field_id('categories_not_in'); ?>"><?php _e( 'Not in any of these categories' ); ?></label>
					<ul><?php echo $this->control_categories( $instance , 'categories_not_in' ); ?></ul>
				</p>
			</div>
		</div>

		<div id="<?php echo $this->get_field_id('tags'); ?>-container" class="postloop container hide-if-js <?php echo $this->tax_posttype_classes('post_tag'); ?>">
			<label for="<?php echo $this->get_field_id('tagsbool'); ?>"><?php _e( 'Tags' ); ?></label>
			<div id="<?php echo $this->get_field_id('tags'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('tagsbool'); ?>" id="<?php echo $this->get_field_id('tagsbool'); ?>" class="widefat">
						<option value="in" <?php selected( $instance['tagsbool'], 'in' ); ?>><?php _e('Any of these tags'); ?></option>
						<option value="and" <?php selected( $instance['tagsbool'], 'and' ); ?>><?php _e('All of these tags'); ?></option>
					</select>
		
					<?php
					$tags_in = array();
					foreach( $instance['tags_in'] as $tag_id ){
						$temp = get_term( $tag_id, 'post_tag' );
						$tags_in[] = $temp->name;
					}
					?>
					<input type="text" value="<?php echo implode( ', ', (array) $tags_in ); ?>" name="<?php echo $this->get_field_name('tags_in'); ?>" id="<?php echo $this->get_field_id('tags_in'); ?>" class="widefat <?php if( count( (array) $tags_in )) echo 'open-on-value'; ?>" />
					<br />
					<small><?php _e( 'Tags, separated by commas.' ); ?></small>

					<br />And terms from<br /><select name="<?php echo $this->get_field_name( 'tags_in_related' ); ?>" id="<?php echo $this->get_field_id( 'tags_in_related' ); ?>" class="widefat <?php if( $instance[ 'tags_in_related' ] ) echo 'open-on-value'; ?>">
						<option value="0" '. <?php selected( (int) $instance[ 'tags_in_related' ] , 0 ) ?> .'></option>
<?php
						foreach( $postloops->instances as $number => $loop ){
							if( $number == $this->number )
								continue;
				
							echo '<option value="'. $number .'" '. selected( (int) $instance[ 'tags_in_related' ] , (int) $number , FALSE ) .'>'. $loop['title'] .'<small> (id:'. $number .')</small></option>';
						}
?>

					</select></li>
				</p>
		
				<p>
					<label for="<?php echo $this->get_field_id('tags_not_in'); ?>"><?php _e( 'With none of these tags' ); ?></label>
					<?php
					$tags_not_in = array();
					foreach( $instance['tags_not_in'] as $tag_id ){
						$temp = get_term( $tag_id, 'post_tag' );
						$tags_not_in[] = $temp->name;
					}
					?>
					<input type="text" value="<?php echo implode( ', ', (array) $tags_not_in ); ?>" name="<?php echo $this->get_field_name('tags_not_in'); ?>" id="<?php echo $this->get_field_id('tags_not_in'); ?>" class="widefat <?php if( count( (array) $tags_not_in )) echo 'open-on-value'; ?>" />
					<br />
					<small><?php _e( 'Tags, separated by commas.' ); ?></small>

					<br />And terms from<br /><select name="<?php echo $this->get_field_name( 'tags_not_in_related' ); ?>" id="<?php echo $this->get_field_id( 'tags_not_in_related' ); ?>" class="widefat <?php if ( $instance['tags_not_in_related' ] ) echo 'open-on-value'; ?>">
						<option value="0" '. <?php selected( $instance['tags_not_in_related'], 0 ); ?> .'></option>
<?php
						foreach( $postloops->instances as $number => $loop ){
							if( $number == $this->number )
								continue;
				
							echo '<option value="'. $number .'" '. selected( (int) $instance[ 'tags_not_in_related' ] , (int) $number , FALSE ) .'>'. $loop['title'] .'<small> (id:'. $number .')</small></option>';
						}
?>

					</select></li>
				</p>
			</div>
		</div>

		<?php $this->control_taxonomies( $instance , $instance['what'] ); ?>

		<div id="<?php echo $this->get_field_id('post__in'); ?>-container" class="postloop container hide-if-js querytype_custom posttype_normal">
			<label for="<?php echo $this->get_field_id('post__in'); ?>"><?php _e( 'Matching any post ID' ); ?></label>
			<div id="<?php echo $this->get_field_id('post__in'); ?>-contents" class="contents hide-if-js">
				<p>
					<input type="text" value="<?php echo implode( ', ', $instance['post__in'] ); ?>" name="<?php echo $this->get_field_name('post__in'); ?>" id="<?php echo $this->get_field_id('post__in'); ?>" class="widefat <?php if( count( $instance['post__in'] )) echo 'open-on-value'; ?>" />
					<br />
					<small><?php _e( 'Page IDs, separated by commas.' ); ?></small>
				</p>
		
				<p>
					<label for="<?php echo $this->get_field_id('post__not_in'); ?>"><?php _e( 'Excluding all these post IDs' ); ?></label> <input type="text" value="<?php echo implode( ', ', $instance['post__not_in'] ); ?>" name="<?php echo $this->get_field_name('post__not_in'); ?>" id="<?php echo $this->get_field_id('post__not_in'); ?>" class="widefat <?php if( count( $instance['post__not_in'] )) echo 'open-on-value'; ?>" />
					<br />
					<small><?php _e( 'Page IDs, separated by commas.' ); ?></small>
				</p>
			</div>
		</div>

		<div id="<?php echo $this->get_field_id('comments'); ?>-container" class="postloop container hide-if-js querytype_custom posttype_normal">
			<label for="<?php echo $this->get_field_id('comments'); ?>"><?php _e( 'Comments' ); ?></label>
			<div id="<?php echo $this->get_field_id('comments'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('comments'); ?>" id="<?php echo $this->get_field_id('comments'); ?>" class="widefat <?php if( 'unset' <> $instance['comments'] ) echo 'open-on-value'; ?>">
						<option value="unset" <?php selected( $instance['comments'], 'unset' ); ?>><?php _e(''); ?></option>
						<option value="yes" <?php selected( $instance['comments'], 'yes' ); ?>><?php _e('Has comments'); ?></option>
						<option value="no" <?php selected( $instance['comments'], 'no' ); ?>><?php _e('Does not have comments'); ?></option>
					</select>
				</p>
			</div>
		</div>

		<div id="<?php echo $this->get_field_id('age'); ?>-container" class="postloop container hide-if-js querytype_custom posttype_normal">
			<label for="<?php echo $this->get_field_id('age_num'); ?>"><?php _e('Date published'); ?></label>
			<div id="<?php echo $this->get_field_id('age'); ?>-contents" class="contents hide-if-js">
				<p>
					<select id="<?php echo $this->get_field_id('age_bool'); ?>" name="<?php echo $this->get_field_name('age_bool'); ?>">
						<option value="newer" <?php selected( $instance['age_bool'], 'newer' ) ?>>Newer than</option>
						<option value="older" <?php selected( $instance['age_bool'], 'older' ) ?>>Older than</option>
					</select>
					<input type="text" value="<?php echo $instance['age_num']; ?>" name="<?php echo $this->get_field_name('age_num'); ?>" id="<?php echo $this->get_field_id('age_num'); ?>" size="1" class="<?php if( 0 < $instance['age_num'] ) echo 'open-on-value'; ?>" />
					<select id="<?php echo $this->get_field_id('age_unit'); ?>" name="<?php echo $this->get_field_name('age_unit'); ?>">
						<option value="day" <?php selected( $instance['age_unit'], 'day' ) ?>>Day(s)</option>
						<option value="month" <?php selected( $instance['age_unit'], 'month' ) ?>>Month(s)</option>
						<option value="year" <?php selected( $instance['age_unit'], 'year' ) ?>>Year(s)</option>
					</select>
				</p>
			</div>
		</div>

		<?php if( $other_instances = $this->control_instances( $instance['relatedto'] )): ?>
			<div id="<?php echo $this->get_field_id('relationship'); ?>-container" class="postloop container hide-if-js querytype_custom posttype_normal">
				<label for="<?php echo $this->get_field_id('relationship'); ?>"><?php _e('Related to other posts'); ?></label>
				<div id="<?php echo $this->get_field_id('relationship'); ?>-contents" class="contents hide-if-js">
					<p>
						<select id="<?php echo $this->get_field_id('relationship'); ?>" name="<?php echo $this->get_field_name('relationship'); ?>">
							<option value="excluding" <?php selected( $instance['relationship'], 'excluding' ) ?>>Excluding those</option>
							<option value="similar" <?php selected( $instance['relationship'], 'similar' ) ?>>Similar to</option>
						</select>
						<?php _e('items shown in'); ?>
						<ul>
						<?php echo $other_instances; ?>
						</ul>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<div id="<?php echo $this->get_field_id('count'); ?>-container" class="postloop container hide-if-js querytype_custom posttype_normal">
			<label for="<?php echo $this->get_field_id('count'); ?>"><?php _e( 'Number of items to show' ); ?></label>
			<div id="<?php echo $this->get_field_id('count'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('count'); ?>" id="<?php echo $this->get_field_id('count'); ?>" class="widefat">
					<?php for( $i = 1; $i < 51; $i++ ){ ?>
						<option value="<?php echo $i; ?>" <?php selected( $instance['count'], $i ); ?>><?php echo $i; ?></option>
					<?php } ?>
					</select>
				</p>
			</div>
		</div>

		<div id="<?php echo $this->get_field_id('order'); ?>-container" class="postloop container hide-if-js querytype_custom posttype_normal">
			<label for="<?php echo $this->get_field_id('order'); ?>"><?php _e( 'Ordered by' ); ?></label>
			<div id="<?php echo $this->get_field_id('order'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('order'); ?>" id="<?php echo $this->get_field_id('order'); ?>" class="widefat">
							<option value="age_new" <?php selected( $instance['order'], 'age_new' ); ?>><?php _e('Newest first'); ?></option>
							<option value="age_old" <?php selected( $instance['order'], 'age_old' ); ?>><?php _e('Oldest first'); ?></option>
							<option value="comment_new" <?php selected( $instance['order'], 'comment_new' ); ?>><?php _e('Recently commented'); ?></option>
							<option value="comment_count" <?php selected( $instance['order'], 'comment_count' ); ?>><?php _e('Comment count'); ?></option>
							<option value="title_az" <?php selected( $instance['order'], 'title_az' ); ?>><?php _e('Title A-Z'); ?></option>
							<option value="title_za" <?php selected( $instance['order'], 'title_za' ); ?>><?php _e('Title Z-A'); ?></option>
							<?php if( is_object( $bsuite )): ?>
								<option value="pop_recent" <?php selected( $instance['order'], 'pop_recent' ); ?>><?php _e('Recently Popular'); ?></option>
							<?php endif; ?>
							<option value="rand" <?php selected( $instance['order'], 'rand' ); ?>><?php _e('Random'); ?></option>
					</select>
				</p>
			</div>
		</div>

		<div id="<?php echo $this->get_field_id('template'); ?>-container" class="postloop container querytype_normal posttype_normal">
			<label for="<?php echo $this->get_field_id('template'); ?>"><?php _e( 'Template' ); ?></label>
			<div id="<?php echo $this->get_field_id('template'); ?>-contents" class="contents hide-if-js">
				<p>
					<select name="<?php echo $this->get_field_name('template'); ?>" id="<?php echo $this->get_field_id('template'); ?>" class="widefat">
						<?php $this->control_template_dropdown( $instance['template'] ); ?>
					</select>
				</p>
			</div>
		</div>

		<?php
		// weird feature to separate a single postloop into multiple widgets
		?>
		<div id="<?php echo $this->get_field_id('offset'); ?>-container" class="postloop container querytype_normal posttype_normal">
			<label for="<?php echo $this->get_field_id('offset'); ?>"><?php _e( 'Loop offset' ); ?></label>
			<div id="<?php echo $this->get_field_id('offset'); ?>-contents" class="contents hide-if-js">
				<p>
					<label for="<?php echo $this->get_field_id('offset_run'); ?>"><?php _e( 'From items in the loop, show N items' ); ?></label>
					<select name="<?php echo $this->get_field_name('offset_run'); ?>" id="<?php echo $this->get_field_id('offset_run'); ?>" class="widefat">
					<option value="" <?php selected( $instance['offset_run'], $i ); ?>></option>
					<?php for( $i = 1; $i < 51; $i++ ){ ?>
						<option value="<?php echo $i; ?>" <?php selected( $instance['offset_run'], $i ); ?>><?php echo $i; ?></option>
					<?php } ?>
					</select>
				</p>
				<p>
					<label for="<?php echo $this->get_field_id('offset_start'); ?>"><?php _e( 'Starting with the item' ); ?></label>
					<select name="<?php echo $this->get_field_name('offset_start'); ?>" id="<?php echo $this->get_field_id('offset_start'); ?>" class="widefat">
					<option value="" <?php selected( $instance['offset_start'], $i ); ?>></option>
					<?php for( $i = 1; $i < 51; $i++ ){ ?>
						<option value="<?php echo $i; ?>" <?php selected( $instance['offset_start'], $i ); ?>><?php echo $i; ?></option>
					<?php } ?>
					</select>
				</p>
			</div>
		</div>
<?php
		if( function_exists( 'get_intermediate_image_sizes' ))
		{
?>
			<div id="<?php echo $this->get_field_id('thumbnail_size'); ?>-container" class="postloop container querytype_normal posttype_normal">
				<label for="<?php echo $this->get_field_id('thumbnail_size'); ?>"><?php _e( 'Thumbnail Size' ); ?></label>
				<div id="<?php echo $this->get_field_id('thumbnail_size'); ?>-contents" class="contents hide-if-js">
					<p>
						<select name="<?php echo $this->get_field_name('thumbnail_size'); ?>" id="<?php echo $this->get_field_id('thumbnail_size'); ?>" class="widefat">
							<?php $this->control_thumbnails( $instance['thumbnail_size'] ); ?>
						</select>
					</p>
				</div>
			</div>
<?php
		}

		if ( isset( $this->justupdated ) && $this->justupdated )
		{
?>
<script type="text/javascript">
	postloops_widgeteditor_update( '<?php echo $this->get_field_id('title'); ?>' );
</script>

<?php
		}
	}



	function get_post_types()
	{
		return get_post_types( array( 'public' => TRUE , 'publicly_queryable' => TRUE , ) , 'names' , 'or' ); // trivia: 'pages' are public, but not publicly queryable
	}



	function control_thumbnails( $default = 'nines-thumbnail-small' )
	{
		if( ! function_exists( 'get_intermediate_image_sizes' ))
			return;

		foreach ( (array) get_intermediate_image_sizes() as $size ) :
			if ( $default == $size )
				$selected = " selected='selected'";
			else
				$selected = '';
			echo "\n\t<option value=\"". $size .'" '. $selected .'>'. $size .'</option>';
		endforeach;
	}

	function control_categories( $instance , $whichfield = 'categories_in' )
	{

		// get the regular category list
		$list = array();
		$items = get_categories( array( 'style' => FALSE, 'echo' => FALSE, 'hierarchical' => FALSE ));
		foreach( $items as $item )
		{
			$list[] = '<li>
				<label for="'. $this->get_field_id( $whichfield .'-'. $item->term_id) .'"><input id="'. $this->get_field_id( $whichfield .'-'. $item->term_id) .'" name="'. $this->get_field_name( $whichfield ) .'['. $item->term_id .']" type="checkbox" value="1" '. ( isset( $instance[ $whichfield ][ $item->term_id ] ) ? 'checked="checked" class="open-on-value" ' : 'class="checkbox"' ) .'/> '. $item->name .'</label>
			</li>';
		}

		// get the select list to choose categories from items shown in another instance
		global $postloops;
		$instance[ $whichfield .'_related' ] = isset( $instance[ $whichfield .'_related' ] ) ? $instance[ $whichfield .'_related' ] : 0;

		$related_instance_select = '<option value="0" '. selected( $instance[ $whichfield .'_related' ], 0, FALSE ) . '></option>';
		foreach( $postloops->instances as $number => $loop )
		{
			if( $number == $this->number )
				continue;

			$related_instance_select .= '<option value="'. $number .'" '. selected( (int) $instance[ $whichfield .'_related' ] , (int) $number , FALSE ) .'>'. $loop['title'] .'<small> (id:'. $number .')</small></option>';
		}

		$list[] = '<li>Categories from items shown in<br /><select name="'. $this->get_field_name( $whichfield .'_related' ) .'" id="'. $this->get_field_id( $whichfield .'_related' ) .'" class="widefat '. ( $instance[ $whichfield .'_related' ] ?  'open-on-value' : '' ) .'">'. $related_instance_select . '</select></li>';
	
		return implode( "\n", $list );
	}
	
	function control_taxonomies( $instance , $post_type )
	{
		global $postloops;

		if( $post_type == 'normal' )
			return;

		foreach( get_object_taxonomies( $post_type ) as $taxonomy )
		{

			if( $taxonomy == 'category' || $taxonomy == 'post_tag' )
				continue;

			$instance['tax_'. $taxonomy .'_in'] = isset( $instance['tax_'. $taxonomy .'_in'] ) ? $instance['tax_'. $taxonomy .'_in'] : array();
			$instance['tax_'. $taxonomy .'_in_related'] = isset( $instance['tax_'. $taxonomy .'_in_related'] ) ? $instance['tax_'. $taxonomy .'_in_related'] : 0;
			$instance['tax_'. $taxonomy .'_not_in'] = isset( $instance['tax_'. $taxonomy .'_not_in'] ) ? $instance['tax_'. $taxonomy .'_not_in'] : array();
			$instance['tax_'. $taxonomy .'_not_in_related'] = isset( $instance['tax_'. $taxonomy .'_not_in_related'] ) ? $instance['tax_'. $taxonomy .'_not_in_related'] : 0;

			$tax = get_taxonomy( $taxonomy );
			$tax_name = $tax->label;
?>
			<div id="<?php echo $this->get_field_id( 'tax_'. $taxonomy ); ?>-container" class="postloop container hide-if-js <?php echo $this->tax_posttype_classes($taxonomy); ?>">
				<label for="<?php echo $this->get_field_id( 'tax_'. $taxonomy .'_bool' ); ?>"><?php echo $tax_name; ?></label>
				<div id="<?php echo $this->get_field_id( 'tax_'. $taxonomy ); ?>-contents" class="contents hide-if-js">
					<p>
						<select name="<?php echo $this->get_field_name('tax_'. $taxonomy .'_bool'); ?>" id="<?php echo $this->get_field_id('tax_'. $taxonomy .'_bool'); ?>" class="widefat">
							<option value="in" <?php selected( $instance['tax_'. $taxonomy .'_bool'], 'in' ); ?>><?php _e('Any of these terms'); ?></option>
							<option value="and" <?php selected( $instance['tax_'. $taxonomy .'_bool'], 'and' ); ?>><?php _e('All of these terms'); ?></option>
						</select>
			
						<?php
						$tags_in = array();
						foreach( $instance['tax_'. $taxonomy .'_in'] as $tag_id ){
							$temp = get_term( $tag_id, $taxonomy );
							if ( is_object( $temp ) ) {
								$tags_in[] = $temp->name;
							}
						}
						?>
						<input type="text" value="<?php echo implode( ', ', (array) $tags_in ); ?>" name="<?php echo $this->get_field_name('tax_'. $taxonomy .'_in'); ?>" id="<?php echo $this->get_field_id('tax_'. $taxonomy .'_in'); ?>" class="widefat <?php if( count( (array) $tags_in )) echo 'open-on-value'; ?>" />
						<br />
						<small><?php _e( 'Terms, separated by commas.' ); ?></small>

						<br />And terms from<br /><select name="<?php echo $this->get_field_name( 'tax_'. $taxonomy .'_in_related' ); ?>" id="<?php echo $this->get_field_id( 'tax_'. $taxonomy .'_in_related' ); ?>" class="widefat <?php if( $instance[ 'tax_'. $taxonomy .'_in_related' ] ) echo 'open-on-value'; ?>">
							<option value="0" '. <?php selected( $instance[ 'tax_'. $taxonomy .'_in_related' ] , 0 ) ?> .'></option>
<?php
							foreach( $postloops->instances as $number => $loop ){
								if( $number == $this->number )
									continue;
					
								echo '<option value="'. $number .'" '. selected( (int) $instance[ 'tax_'. $taxonomy .'_in_related' ] , (int) $number , FALSE ) .'>'. $loop['title'] .'<small> (id:'. $number .')</small></option>';
							}
?>
	
						</select></li>
					</p>
		
					<p>
						<label for="<?php echo $this->get_field_id('tax_'. $taxonomy .'_not_in'); ?>"><?php _e( 'With none of these terms' ); ?></label>
						<?php
						$tags_not_in = array();
						foreach( (array) $instance['tax_'. $taxonomy .'_not_in'] as $tag_id ){
							$temp = get_term( $tag_id, $taxonomy );
							if ( is_object( $temp ) ) {
								$tags_not_in[] = $temp->name;
							}
						}
						?>
						<input type="text" value="<?php echo implode( ', ', (array) $tags_not_in ); ?>" name="<?php echo $this->get_field_name('tax_'. $taxonomy .'_not_in'); ?>" id="<?php echo $this->get_field_id('tax_'. $taxonomy .'_not_in'); ?>" class="widefat <?php if( count( (array) $tags_not_in )) echo 'open-on-value'; ?>" />
						<br />
						<small><?php _e( 'Terms, separated by commas.' ); ?></small>

						<br />And terms from<br /><select name="<?php echo $this->get_field_name( 'tax_'. $taxonomy .'_not_in_related' ); ?>" id="<?php echo $this->get_field_id( 'tax_'. $taxonomy .'_not_in_related' ); ?>" class="widefat <?php if( $instance[ 'tax_'. $taxonomy .'_not_in_related' ] ) echo 'open-on-value'; ?>">
							<option value="0" '. <?php selected( (int) $instance[ 'tax_'. $taxonomy .'_not_in_related' ] , 0 ) ?> .'></option>
<?php
							foreach( $postloops->instances as $number => $loop ){
								if( $number == $this->number )
									continue;
					
								echo '<option value="'. $number .'" '. selected( (int) $instance[ 'tax_'. $taxonomy .'_not_in_related' ] , (int) $number , FALSE ) .'>'. $loop['title'] .'<small> (id:'. $number .')</small></option>';
							}
?>

						</select></li>
					</p>
				</div>
			</div>
<?php
		}
	}
	
	function control_instances( $selected = array() )
	{
		global $postloops;

		$list = array();
		foreach( $postloops->instances as $number => $instance )
		{
			if( $number == $this->number )
				continue;

			$list[] = '<li>
				<label for="'. $this->get_field_id( 'relatedto-'. $number ) .'"><input type="checkbox" value="'. $number .'" '.( in_array( $number, (array) $selected ) ? 'checked="checked" class="checkbox open-on-value"' : 'class="checkbox"' ) .' id="'. $this->get_field_id( 'relatedto-'. $number) .'" name="'. $this->get_field_name( 'relatedto' ) .'['. $number .']" /> '. $instance['title'] .'<small> (id:'. $number .')</small></label>
			</li>';
		}
	
		return implode( "\n", $list );
	}
	
	function control_template_dropdown( $default = '' )
	{
		global $postloops;

		foreach ( $postloops->get_actions('post') as $template => $info ) :
			if ( $default == $template )
				$selected = " selected='selected'";
			else
				$selected = '';
			echo "\n\t<option value=\"" .$template .'" '. $selected .'>'. $info['name'] .'</option>';
		endforeach;
	}

	function tax_posttype_classes( $taxonomy ) {
		$tax = get_taxonomy($taxonomy);

		if( ! $tax || count( $tax->object_type ) == 0 ) {
			return '';
		}

		return 'querytype_custom ' . implode( ' posttype_', $tax->object_type );
	}
}// end bSuite_Widget_Postloop


// register these widgets
function postloops_widgets_init() {
	register_widget( 'bSuite_Widget_PostLoop' );
}
add_action('widgets_init', 'postloops_widgets_init', 1);

/*
Reminder to self: the widget objects and their vars can be found in here:

global $wp_widget_factory;
print_r( $wp_widget_factory );

*/
