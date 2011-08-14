<?php

class TagCloud extends Plugin
{
	private $config = array();
	private $class_name = '';
	private $cache = array();
	private $default_options = array (
		'num_tag' => '',
		'hide_tags' => '',
		'tag_by_color' => 'Y',
		'least_color' => '#444444',
		'most_color' => '#cccccc',
		'tag_by_size' => 'Y',
		'least_size' => '80',
		'most_size' => '250',
		'font_unit' => '%',
		);

	// Set the default options
	public function action_plugin_activation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			$this->class_name = strtolower( get_class( $this ) );
			foreach ( $this->default_options as $name => $value ) {
				$current_value = Options::get( $this->class_name . '__' . $name );
				if ( !isset( $current_value) ) {
					Options::set( $this->class_name . '__' . $name, $value );
				}
			}
		}
	}

	public function action_plugin_deactivation( $file )
	{
		if ( realpath( $file ) == __FILE__ ) {
			$this->expire_cache();
		}
	}

	/**
	 * Plugin init action, executed when plugins are initialized.
	 */
	public function action_init()
	{
		$this->class_name = strtolower( get_class( $this ) );
		foreach ( $this->default_options as $name => $unused ) {
			$this->config[$name] = Options::get( $this->class_name . '__' . $name );
		}
		$this->add_template( 'block.rnbtagcloud', dirname(__FILE__) . '/block.rnbtagcloud.php' );
	}
	
	/**
	 * Make the block available
	 **/
	public function filter_block_list( $block_list )
	{
		$blocklist[ 'rnbtagcloud' ] = _t( 'RN Block Tag Cloud' );
		return $blocklist;
	}
	
	/**
	 * Put data into the block
	 **/
	public function action_block_content_rnbtagcloud( $block )
	{
		$block->tagcloudhtml = $this->build_tag_cloud( $block->tag_number );
	}
	
	public function action_block_form_rnbtagcloud( $form, $block )
	{
		$form->append( 'text', 'tag_number', $block, _t( 'Number of tags tp display:', 'rnbtagcloud' ) );
	}

	/**
	 * Executes when the admin plugins page wants to know if plugins have configuration links to display.
	 *
	 * @param array $actions An array of existing actions for the specified plugin id.
	 * @param string $plugin_id A unique id identifying a plugin.
	 * @return array An array of supported actions for the named plugin
	 */
	public function filter_plugin_config( $actions, $plugin_id )
	{
		// Is this plugin the one specified?
		if ( $plugin_id == $this->plugin_id() ) {
			// Add a 'Configure' action in the admin's list of plugins
			$actions[] = _t( 'Configure' );
			$actions[] = _t( 'Clear Cache' );
		}
		return $actions;
	}

	/**
	 * Executes when the admin plugins page wants to display the UI for a particular plugin action.
	 * Displays the plugin's UI.
	 *
	 * @param string $plugin_id The unique id of a plugin
	 * @param string $action The action to display
	 */
	public function action_plugin_ui( $plugin_id, $action )
	{
		// Display the UI for this plugin?
		if ( $plugin_id == $this->plugin_id() ) {
			// Depending on the action specified, do different things
			switch ( $action ) {
				case _t( 'Configure' ):
					$ui = new FormUI( $this->class_name );
					$num_tag = $ui->append( 'text', 'num_tag', 'option:' . $this->class_name . '__num_tag', _t( 'No. of tags shown (blank for all tags)' ) );

					$hide_tags = $ui->append( 'textmulti', 'hide_tags', 'option:' . $this->class_name . '__hide_tags', _t( 'Tag(s) to be hidden' ) );

					$tag_by_color = $ui->append( 'select', 'tag_by_color', 'option:' . $this->class_name . '__tag_by_color', _t( 'Popularity by Color?' ) );
					$tag_by_color->options = array( '' => '', 'Y' => 'Yes', 'N' => 'No' );
					$tag_by_color->add_validator( 'validate_tag_by_color' );

					$least_color = $ui->append( 'text', 'least_color', 'option:' . $this->class_name . '__least_color', _t( 'Color of least popular tag (in hex value, e.g. #444444)' ) );
					$least_color->add_validator( 'validate_color_code' );

					$most_color = $ui->append( 'text', 'most_color', 'option:' . $this->class_name . '__most_color', _t( 'Color of most popular tag (in hex value, e.g. #cccccc)' ) );
					$most_color->add_validator( 'validate_color_code' );

					$tag_by_size = $ui->append( 'select', 'tag_by_size', 'option:' . $this->class_name . '__tag_by_size', _t( 'Popularity by Size?' ) );
					$tag_by_size->options = array( '' => '', 'Y' => 'Yes', 'N' => 'No' );
					$tag_by_size->add_validator( 'validate_tag_by_size' );

					$least_size = $ui->append( 'text', 'least_size', 'option:' . $this->class_name . '__least_size', _t( 'Size of least popular tag' ) );
					$least_size->add_validator( 'validate_size' );

					$most_size = $ui->append( 'text', 'most_size', 'option:' . $this->class_name . '__most_size', _t( 'Size of most popular tag' ) );
					$most_size->add_validator( 'validate_size' );

					$font_unit = $ui->append( 'select', 'font_unit', 'option:' . $this->class_name . '__font_unit', _t( 'Font Unit' ) );
					$font_unit->options = array( '' => '', '%' => '%', 'em' => 'em', 'px' => 'px' );
					$font_unit->add_validator( 'validate_size' );

					$ui->append( 'submit', 'save', _t( 'Save' ) );
					$ui->set_option( 'success_message', _t( 'Configuration saved' ) );

					$ui->on_success( array( $this, 'updated_config' ) );
					$ui->out();
					break;
				case _t( 'Clear Cache' ):
					$this->expire_cache();
					echo '<p>' . _t( 'Cache has been cleared.' ) . '</p>';
					break;
			}
		}
	}

	public function updated_config( $ui )
	{
		$ui->save();
		$this->expire_cache();
		return false;
	}

	private function expire_cache()
	{
		foreach ( $this->cache as $num_tag => $cache_name ) {
			//Cache::expire( $cache_name );
		}
	}

	public function theme_tag_cloud( $theme, $num_tag = 0 )
	{
		if ( $this->plugin_configured() ) {
			if ( array_key_exists( $num_tag, $this->cache ) ) {
				if ( Cache::has( $this->cache[$num_tag] ) ) {
					$tag_cloud = Cache::get( $this->cache[$num_tag] );
				}
				else {
					$tag_cloud = $this->build_tag_cloud( $num_tag );
					Cache::set( $this->cache[$num_tag], $tag_cloud );
				}
			}
			else {
				$tag_cloud = $this->build_tag_cloud( $num_tag );
				$this->cache[$num_tag] = Site::get_url( 'host' ) . ':' . $this->class_name . ':' . $num_tag;
				Cache::set( $this->cache[$num_tag], $tag_cloud );
			}
		}
		else {
			$tag_cloud = '<ul><li>' . _t( 'Plugin not yet configured' ) . '</li></ul>';
		}

		return $tag_cloud;
	}

	public function filter_validate_tag_by_color( $valid, $value )
	{
		if ( empty( $value ) || $value == '' ) {
			return array( _t( 'A value for this field is required.' ) );
		}
		$this->config['tag_by_color'] = $value;
		return array();
	}

	public function filter_validate_color_code( $valid, $value )
	{
		if ( !empty( $this->config['tag_by_color'] ) && 'Y' == $this->config['tag_by_color'] ) {
			if ( empty( $value ) ) {
				return array( _t( "A value for this field is required when using 'Popularity by Color'." ) );
			}
			if ( 0 == preg_match( '/^#([0-9a-f]{1,2}){3}$/i', $value ) ) {
				return array( _t( "Format must be in #dddddd, where 'd' is 0-9 or a-f" ) );
			}
		}
		return array();
	}

	public function filter_validate_tag_by_size( $valid, $value )
	{
		if ( empty( $value ) || $value == '' ) {
			return array( _t( 'A value for this field is required.' ) );
		}
		$this->config['tag_by_size'] = $value;
		return array();
	}

	public function filter_validate_size( $valid, $value )
	{
		if ( !empty( $this->config['tag_by_size'] ) && 'Y' == $this->config['tag_by_size' ] ) {
			if ( empty( $value ) ) {
				return array( _t( "A value for this field is required when using 'Popularity by Size'." ) );
			}
		}
		return array();
	}

	private function plugin_configured()
	{
		if ( empty( $this->config['tag_by_color'] ) ||
			  empty( $this->config['tag_by_size'] ) ) {
		  return false;
		}
		if ( !empty( $this->config['tag_by_color'] ) ) {
			if ( empty( $this->config['least_color'] ) ||
				  empty( $this->config['most_color'] ) ) {
				return false;
			}
		}
		if ( !empty( $this->config['tag_by_size'] ) ) {
			if ( empty( $this->config['least_size'] ) ||
				  empty( $this->config['most_size'] ) ||
				  empty( $this->config['font_unit'] ) ) {
				return false;
			}
		}
		return true;
	}

	private function get_color_for_weight( $weight )
	{
      if ( $weight ) {
         $weight = $weight / 100;

         $minr = hexdec( substr( $this->config['least_color'], 1, 2 ) );
         $ming = hexdec( substr( $this->config['least_color'], 3, 2 ) );
         $minb = hexdec( substr( $this->config['least_color'], 5, 2 ) );

         $maxr = hexdec( substr( $this->config['most_color'], 1, 2 ) );
         $maxg = hexdec( substr( $this->config['most_color'], 3, 2 ) );
         $maxb = hexdec( substr( $this->config['most_color'], 5, 2 ) );

         $r = dechex( intval( ( ( $maxr - $minr ) * $weight ) + $minr ) );
         $g = dechex( intval( ( ( $maxg - $ming ) * $weight ) + $ming ) );
         $b = dechex( intval( ( ( $maxb - $minb ) * $weight ) + $minb ) );

         if ( strlen( $r ) == 1 ) $r = "0" . $r;
         if ( strlen( $g ) == 1 ) $g = "0" . $g;
         if ( strlen( $b ) == 1 ) $b = "0" . $b;

         return "#$r$g$b";
      }
	}

	private function get_font_size_for_weight( $weight )
	{
      if ( $this->config['most_size'] > $this->config['least_size'] ) {
         $fontsize = ( ( $weight / 100 ) * ( $this->config['most_size'] - $this->config['least_size'] ) ) + $this->config['least_size'];
      }
		else {
         $fontsize = ( ( ( 100 - $weight ) / 100 ) * ( $this->config['most_size'] - $this->config['least_size'] ) ) + $this->config['most_size'];
      }

      return intval( $fontsize ) . $this->config['font_unit'];
   }

	private function get_hide_tag_list()
	{
		if ( !empty( $this->config['hide_tags' ] ) ) {
			$hide_tag_list = '';
			foreach ( $this->config['hide_tags'] as $tag ) {
				$hide_tag_list .= ( $hide_tag_list == '' ? "'{$tag}'" : ", '{$tag}'" );
			}
			$hide_tag_list = "AND t.term NOT IN ({$hide_tag_list})";
			return $hide_tag_list;
		}
		else {
			return '';
		}
	}

	private function get_total_tag_usage_count()
	{
		$post_status = Post::status( 'published' );
		$hide_tags = self::get_hide_tag_list();

		$sql = "
			SELECT COUNT(ot.object_id) AS cnt
			FROM {object_terms} ot
			INNER JOIN {posts} p
			ON ot.object_id = p.id
			INNER JOIN {terms} t
			ON ot.term_id = t.id
			WHERE p.status = {$post_status}
			{$hide_tags}";
		$result = DB::get_row( $sql );

		return ( !empty( $result ) ? $result->cnt : 0 );
	}

	private function get_most_popular_tag_count()
	{
		$post_status = Post::status( 'published' );
		$hide_tags = self::get_hide_tag_list();

		$sql = "
			SELECT COUNT(ot.object_id) AS cnt
			FROM {posts} p
			INNER JOIN {object_terms} ot
			ON p.id = ot.object_id
			INNER JOIN {terms} t
			ON ot.term_id = t.id
			WHERE p.status = {$post_status}
			{$hide_tags}
			GROUP BY t.id
			ORDER BY cnt DESC
			LIMIT 1";
		$result = DB::get_row( $sql );

		return ( !empty( $result ) ? $result->cnt : 0 );
	}

	private function build_tag_cloud( $num_tag )
	{
		$tag_cloud = '';
		$post_status = Post::status( 'published' );

		if ( empty( $num_tag ) ) {
			$limit = ( empty( $this->config['num_tag'] ) ? '' : "LIMIT {$this->config['num_tag']}" );
		}
		else {
			$limit = "LIMIT {$num_tag}";
		}
		$hide_tags = self::get_hide_tag_list();
		$total_tag_cnt = self::get_total_tag_usage_count();
		$most_popular_tag_cnt = self::get_most_popular_tag_count();

		// Get tag and usage count descending
		$sql = "
			SELECT t.term_display AS tag_text, t.term AS tag_slug, t.id AS id,
				COUNT(ot.object_id) AS cnt,
				COUNT(ot.object_id) * 100 / {$total_tag_cnt} AS weight,
				COUNT(ot.object_id) * 100 / {$most_popular_tag_cnt} AS relative_weight
			FROM {posts} p
			INNER JOIN {object_terms} ot
			ON p.id = ot.object_id
			INNER JOIN {terms} t
			ON ot.term_id = t.id
			WHERE p.status = {$post_status}
			{$hide_tags}
			GROUP BY t.term_display, t.term, t.id
			ORDER BY weight DESC
			{$limit}";
		$results = DB::get_results( $sql );

		sort( $results );
		$tag_cloud .= "<ul class=\"tag-cloud\">\n";
		if ( $results ) {
			foreach ( $results as $tag ) {
				$style_str = '';
				if ( 'y' == strtolower( $this->config['tag_by_size'] ) ) {
					$style_str = 'style="font-size: ' . self::get_font_size_for_weight( $tag->relative_weight ) . ';';
				}
				if ( 'y' == strtolower( $this->config['tag_by_color'] ) ) {
					if ( '' == $style_str ) {
						$style_str = 'style="color: ' . self::get_color_for_weight( $tag->relative_weight ) . ';';
					}
					else {
						$style_str .= ' color: ' . self::get_color_for_weight( $tag->relative_weight ) . ';';
					}
				}
				$style_str .= ( '' == $style_str ? '' : '"' );

				$tag_cloud .= '<li><a ' . $style_str . ' href="' . URL::get( 'display_entries_by_tag', array ( 'tag' => $tag->tag_slug ), false ) . '" rel="tag" title="' . $tag->tag_text . " ({$tag->cnt})" . '">'. $tag->tag_text . '</a></li>';
				$tag_cloud .= "\n";
			}
		}
		else {
			$tag_cloud .= "<li>" . _t(' No tag' ) . "</li>\n";
		}
		$tag_cloud .= "</ul>\n";

		return $tag_cloud;
	}

	public function action_post_insert_after( $post )
	{
		if ( Post::status_name( $post->status ) == 'published' ) {
			$this->expire_cache();
		}
	}

	public function action_post_update_after( $post )
	{
		if ( Post::status_name( $post->status ) == 'published' ) {
			$this->expire_cache();
		}
	}

	public function action_post_delete_after( $post )
	{
		if ( Post::status_name( $post->status ) == 'published' ) {
			$this->expire_cache();
		}
	}

	public function action_tag_insert_after( $tag )
	{
		$this->expire_cache();
	}

	public function action_tag_update_after( $tag )
	{
		$this->expire_cache();
	}

	public function action_tag_delete_after( $tag )
	{
		$this->expire_cache();
	}
}
?>
