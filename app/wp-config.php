<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'ysrhf3tdofmqkspe' );

/** Database hostname */
define( 'DB_HOST', 'mariadb-rv3gux' );

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
define( 'AUTH_KEY',         'J!f+u`3al%{0!pI}#ekHMvb}Iv>U!*cLv@1wd)(dP`HFMx,@:%AIK)t1ILdP,O%P' );
define( 'SECURE_AUTH_KEY',  't/pbqqzTxo;W_ea&&SwR#mDIFr#yUDyyyzN[ILU-*Sx]L-l$kb(|/-Pb_$erEdGp' );
define( 'LOGGED_IN_KEY',    '_Z-B2pI=|v[G$.Iqtuhfl%`/pZk26Faf=8e<2JLUA27Zz,]YHnlCe%z>N|fm9Ydv' );
define( 'NONCE_KEY',        ']VD0/K?q(kqxe[,wn-ih|)EDbmIj($y?K/b`U6Fk1r_V~_OU7KnfMbh]kp;}A;k9' );
define( 'AUTH_SALT',        'T3EXcbG1_m+[,szB:`0pP_c.vsi<QyQjpL.vJNd@-8sklAsVGbT)941T(sf@5){E' );
define( 'SECURE_AUTH_SALT', 'O7y:H5m_O1FE1D5Mv5pQDIhxO[JW>ILw#ClNQuABEssiaLgk/k-(}rfH,WpmT[gW' );
define( 'LOGGED_IN_SALT',   'oov=>Tq^J^;}]c`_QCb)du^GA9w~O0^tUJnoxMyl1%m<,f}1E-R]il(h~0W/+:~q' );
define( 'NONCE_SALT',       'uTpg-v4)qLn-LGQ D(W~h6a#K2-|+(_haF1<{{jRj=yDg_PrMdXM:zD)[1.?xAva' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
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
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */
$_SERVER['HTTPS'] = 'on';
define("FS_METHOD","direct");

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
