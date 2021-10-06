<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wp_testwp' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', '' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'pp+&Rd!AOwZ-P=$_Mg@S4M{C7!`b]kRFdV;DeonbAHqMD2m~o3cKM2*G6+f;&UUB' );
define( 'SECURE_AUTH_KEY',  '>q8s4pNSp2,eAk+{yw]FO%1y.U%*njzi7`}+,UM$}ybWN{}C^CqB$|}A,74jpKt=' );
define( 'LOGGED_IN_KEY',    '5yJ]twk_Lth|VEZhCaugj*qx]EH59:%mZ)mASA[c9<wbxQ[S.V>Felx>:u*ONU^^' );
define( 'NONCE_KEY',        'l^)!kT*;)VJ}%;UuWmwBDPa_6O5(AEKpgi<jhPqCU82.(+3#k0#INv{c9Q}nyD9*' );
define( 'AUTH_SALT',        'eQiPfkttP=HQkK0@h8YR?;V9`(wmV!)_|N^G6V|y&Tkn3Cc nuYg`fqSH-+x,a#.' );
define( 'SECURE_AUTH_SALT', 'Wt*aLVERL*T/GD}TjA&yTKx?2E8b+vU;k[u*=xX+#U1 0Zymk^vX ]Gn6nDxy>ev' );
define( 'LOGGED_IN_SALT',   'F0R3liXH+?#tv$}yrUZ 34S7Lv;<]U} X~;;~F)TVbR6j5Z;^Ko}4OExf gF75sU' );
define( 'NONCE_SALT',       'uSe%{us+<(#mCbI~=PLe@ex{6oNalh27w*E4_&AK%UR2nleP[I3kT5]q?Balu7VW' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
