<?php
/*
Plugin Name: WebMaestro Settings
Plugin URI: http://webmaestro.fr/wordpress-settings-api-options-pages/
Author: Etienne Baudry
Author URI: http://webmaestro.fr
Description: Simplified options system for WordPress. Generates a default page for settings.
Version: 1.2.3
License: GNU General Public License
License URI: license.txt
Text Domain: wm-settings
GitHub Plugin URI: https://github.com/WebMaestroFr/wm-settings
GitHub Branch: master
*/

if ( ! class_exists( 'WM_Settings' ) ) {

class WM_Settings {

  private $page,
    $title,
    $menu,
    $settings = array(),
    $empty = true;

  public function __construct( $page = 'custom_settings', $title = null, $menu = array(), $settings = array(), $args = array() )
  {
    $this->page = $page;
    $this->title = $title ? $title : __( 'Custom Settings', 'wm-settings' );
    $this->menu = is_array( $menu ) ? array_merge( array(
      'parent'     => 'themes.php',
      'title'      => $this->title,
      'capability' => 'manage_options',
      'icon_url'   => null,
      'position'   => null
    ), $menu ) : false;
    $this->apply_settings( $settings );
    $this->args  = array_merge( array(
      'submit' => __( 'Save Settings', 'wm-settings' ),
      'reset'  => __( 'Reset Settings', 'wm-settings' )
    ), $args );
    add_action( 'admin_menu', array( $this, 'admin_menu' ) );
    add_action( 'admin_init', array( $this, 'admin_init' ) );
  }

  public function apply_settings( $settings )
  {
    foreach ( $settings as $setting => $section ) {
      $section = array_merge( array(
        'title'       => null,
        'description' => null,
        'fields'      => array()
      ), $section );
      foreach ( $section['fields'] as $name => $field ) {
        $field = array_merge( array(
          'type'        => 'text',
          'label'       => null,
          'description' => null,
          'default'     => null,
          'sanitize'    => null,
          'attributes'  => array(),
          'options'     => null,
          'action'      => null
        ), $field );
        if ( $field['type'] === 'action' && is_callable( $field['action'] ) ) {
          add_action( "wp_ajax_{$setting}_{$name}", $field['action'] );
        }
        $section['fields'][$name] = $field;
      }
      $this->settings[$setting] = $section;
      if ( ! get_option( $setting ) ) {
        add_option( $setting, $this->get_defaults( $setting ) );
      }
    }
  }

  private function get_defaults( $setting )
  {
    $defaults = array();
    foreach ( $this->settings[$setting]['fields'] as $name => $field ) {
      if ( $field['default'] !== null ) {
        $defaults[$name] = $field['default'];
      }
    }
    return $defaults;
  }

  public function admin_menu()
  {
    if ( $this->menu ) {
      if ( $this->menu['parent'] ) {
        $page = add_submenu_page( $this->menu['parent'], $this->title, $this->menu['title'], $this->menu['capability'], $this->page, array( $this, 'do_page' ) );
      } else {
        $page = add_menu_page( $this->title, $this->menu['title'], $this->menu['capability'], $this->page, array( $this, 'do_page' ), $this->menu['icon_url'], $this->menu['position'] );
        if ( $this->title !== $this->menu['title'] ) {
          add_submenu_page( $this->page, $this->title, $this->title, $this->menu['capability'], $this->page );
        }
      }
      add_action( 'load-' . $page, array( $this, 'load_page' ) );
    }
  }

  public function admin_init()
  {
    foreach ( $this->settings as $setting => $section ) {
      register_setting( $this->page, $setting, array( $this, 'sanitize_setting' ) );
      add_settings_section( $setting, $section['title'], array( $this, 'do_section' ), $this->page );
      if ( ! empty( $section['fields'] ) ) {
        $this->empty = false;
        $values = self::get_setting( $setting );
        foreach ( $section['fields'] as $name => $field ) {
          $id = $setting . '_' . $name;
          $field = array_merge( array(
            'id'    => $id,
            'name'    => $setting . '[' . $name . ']',
            'value'   => isset( $values[$name] ) ? $values[$name] : null,
            'label_for' => $id
          ), $field );
          add_settings_field( $name, $field['label'], array( __CLASS__, 'do_field' ), $this->page, $setting, $field );
        }
      }
    }
    if ( isset( $_POST["{$this->page}_reset"] ) ) {
      $this->reset();
    }
  }

  public function load_page()
  {
    if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
      do_action( "{$this->page}_settings_updated" );
    }
    add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
  }

  public static function admin_enqueue_scripts()
  {
    wp_enqueue_media();
    wp_enqueue_script( 'wm-settings', plugins_url( 'wm-settings.js' , __FILE__ ), array( 'jquery' ) );
    wp_localize_script( 'wm-settings', 'ajax', array(
      'url' => admin_url( 'admin-ajax.php' ),
      'spinner' => admin_url( 'images/spinner.gif' )
    ) );
    wp_enqueue_style( 'wm-settings', plugins_url( 'wm-settings.css' , __FILE__ ) );
  }

  private function reset()
  {
    foreach ( $this->settings as $setting => $section ) {
      $_POST[$setting] = array_merge( $_POST[$setting], $this->get_defaults( $setting ) );
    }
    add_settings_error( $this->page, 'settings_reset', __( 'Default settings have been reset.' ), 'updated' );
  }

  public function do_page()
  { ?>
    <form action="options.php" method="POST" enctype="multipart/form-data" class="wrap">
      <h2><?php echo $this->title; ?></h2>
      <?php
        // Avoid showing admin notice twice
        // TODO : Target the pages where it happens
        if ( ! in_array( $this->menu['parent'], array( 'options-general.php' ) ) ) {
          settings_errors();
        }
        do_settings_sections( $this->page );
        if ( ! $this->empty ) {
          settings_fields( $this->page );
          submit_button( $this->args['submit'], 'large primary' );
          if ( $this->args['reset'] ) {
            submit_button( $this->args['reset'], 'small', "{$this->page}_reset", true, array( 'onclick' => "return confirm('" . __( 'Do you really want to reset all these settings to their default values ?', 'wm-settings' ) . "');" ) );
          }
        }
      ?>
    </form>
  <?php }

  public function do_section( $args )
  {
    extract( $args );
    if ( $text = $this->settings[$id]['description'] ) {
      echo wpautop( $text );
    }
    echo "<input name='{$id}[{$this->page}_setting]' type='hidden' value='{$id}' />";
  }

  public static function do_field( $args )
  {
    extract( $args );
    $attrs = "name='{$name}'";
    foreach ( $attributes as $k => $v ) {
      $k = sanitize_key( $k );
      $v = esc_attr( $v );
      $attrs .= " {$k}='{$v}'";
    }
    $desc = $description ? "<p class='description'>{$description}</p>" : '';
    switch ( $type )
    {
      case 'checkbox':
        $check = checked( 1, $value, false);
        echo "<label><input {$attrs} id='{$id}' type='checkbox' value='1' {$check} />";
        if ( $description ) { echo " {$description}"; }
        echo "</label>";
        break;

      case 'radio':
        if ( ! $options ) { _e( 'No options defined.', 'wm-settings' ); }
        echo "<fieldset id='{$id}'>";
        foreach ( $options as $v => $label ) {
          $check = checked( $v, $value, false );
          $options[$v] = "<label><input {$attrs} type='radio' value='{$v}' {$check} /> {$label}</label>";
        }
        echo implode( '<br />', $options );
        echo "{$desc}</fieldset>";
        break;

      case 'select':
        if ( ! $options ) { _e( 'No options defined.', 'wm-settings' ); }
        echo "<select {$attrs} id='{$id}'>";
        foreach ( $options as $v => $label ) {
          $select = selected( $v, $value, false );
          echo "<option value='{$v}' {$select} />{$label}</option>";
        }
        echo "</select>{$desc}";
        break;

      case 'media':
        echo "<fieldset class='wm-settings-media' id='{$id}'><input {$attrs} type='hidden' value='{$value}' />";
        if ( $value ) {
          echo wp_get_attachment_image( $value, 'medium' );
        }
        echo "<p><a class='button button-large wm-select-media' title='{$label}'>" . sprintf( __( 'Select %s', 'wm-settings' ), $label ) . "</a> ";
        echo "<a class='button button-small wm-remove-media' title='{$label}'>" . sprintf( __( 'Remove %s', 'wm-settings' ), $label ) . "</a></p>{$desc}</fieldset>";
        break;

      case 'textarea':
        echo "<textarea {$attrs} id='{$id}' class='large-text'>{$value}</textarea>{$desc}";
        break;

      case 'multi':
        if ( ! $options ) { _e( 'No options defined.', 'wm-settings' ); }
        echo "<fieldset id='{$id}'>";
        foreach ( $options as $n => $label ) {
          $a = preg_replace( "/name\=\'(.+)\'/", "name='$1[{$n}]'", $attrs );
          $check = checked( 1, $value[$n], false);
          $options[$n] = "<label><input {$a} type='checkbox' value='1' {$check} /> {$label}</label>";
        }
        echo implode( '<br />', $options );
        echo "{$desc}</fieldset>";
        break;

      case 'action':
        if ( ! $action ) { _e( 'No action defined.', 'wm-settings' ); }
        echo "<p class='wm-settings-action'><input {$attrs} id='{$id}' type='button' class='button button-large' value='{$label}' /></p>{$desc}";
        break;

      default:
        $v = esc_attr( $value );
        echo "<input {$attrs} id='{$id}' type='{$type}' value='{$v}' class='regular-text' />{$desc}";
        break;
    }
  }

  public function sanitize_setting( $inputs )
  {
    $values = array();
    if ( ! empty( $inputs["{$this->page}_setting"] ) ) {
      $setting = $inputs["{$this->page}_setting"];
      foreach ( $this->settings[$setting]['fields'] as $name => $field ) {
        $input = array_key_exists( $name, $inputs ) ? $inputs[$name] : null;
        if ( $field['sanitize'] ) {
          $values[$name] = call_user_func( $field['sanitize'], $input, $name );
        } else {
          switch ( $field['type'] )
          {
            case 'checkbox':
              $values[$name] = $input ? 1 : 0;
              break;

            case 'radio':
            case 'select':
              $values[$name] = sanitize_key( $input );
              break;

            case 'media':
              $values[$name] = absint( $input );
              break;

            case 'textarea':
              $text = '';
              $nl = "WM-SETTINGS-NEW-LINE";
              $tb = "WM-SETTINGS-TABULATION";
              $lines = explode( $nl, sanitize_text_field( str_replace( "\t", $tb, str_replace( "\n", $nl, $input ) ) ) );
              foreach ( $lines as $line ) {
                $text .= str_replace( $tb, "\t", trim( $line ) ) . "\n";
              }
              $values[$name] = trim( $text );
              break;

            case 'multi':
              if ( ! $input || empty( $field['options'] ) ) { break; }
              foreach ( $field['options'] as $n => $opt ) {
                $input[$n] = empty( $input[$n] ) ? 0 : 1;
              }
              $values[$name] = json_encode( $input );
              break;

            case 'action':
              break;

            case 'email':
              $values[$name] = sanitize_email( $input );
              break;

            case 'url':
              $values[$name] = esc_url_raw( $input );
              break;

            case 'number':
              $values[$name] = floatval( $input );
              break;

            default:
              $values[$name] = sanitize_text_field( $input );
              break;
          }
        }
      }
      return $values;
    }
    return $inputs;
  }

  public static function get_setting( $setting, $option = false ) {
    $setting = get_option( $setting );
    if ( is_array( $setting ) ) {
      if ( $option ) {
        return isset( $setting[$option] ) ? self::parse_multi( $setting[$option] ) : false;
      }
      foreach ( $setting as $k => $v ) {
        $setting[$k] = self::parse_multi( $v );
      }
      return $setting;
    }
    return $option ? false : $setting;
  }

  private static function parse_multi( $result )
  {
    // Check if the result was recorded as JSON, and if so, returns an array instead
    return ( is_string( $result ) && $array = json_decode( $result, true ) ) ? $array : $result;
  }
}

function get_setting( $setting, $option = false )
{
  return WM_Settings::get_setting( $setting, $option );
}

function create_settings_page( $page = 'custom_settings', $title = null, $menu = array(), $settings = array(), $args = array() )
{
  return new WM_Settings( $page, $title, $menu, $settings, $args );
}

}

?>