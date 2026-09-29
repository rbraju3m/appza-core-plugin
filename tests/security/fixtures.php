<?php
// A14 fixtures, run through wp-cli eval-file only. A14_ACTION=mint|revoke|create|state|a4-reset|block|restore|cleanup
// Rows carry "[A14-PROBE]" / "a14-probe-"; rows with no text column are kept in a ledger option.

defined( 'WP_CLI' ) || exit;

use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Models\Reaction;
use FluentCommunity\App\Models\XProfile;
use FluentCommunityPro\App\Models\Follow;
use FluentCommunityPro\App\Models\Moderation;
use FluentMessaging\App\Models\Message;
use FluentMessaging\App\Models\Thread;
use FluentMessaging\App\Models\ThreadUser;

const A14_MARK   = '[A14-PROBE]';
const A14_LEDGER = 'appza_a14_ledger';
const A14_STATUS = 'appza_a14_student_status';

$action  = getenv( 'A14_ACTION' );
$student = get_user_by( 'login', 'appza_test_student' );
if ( ! $student ) {
	echo wp_json_encode( array( 'error' => 'no appza_test_student' ) ) . "\n";
	return;
}
$sid  = (int) $student->ID;
$feed = function ( $tag ) { return Feed::where( 'message', 'like', A14_MARK . ' ' . $tag . '%' )->first(); };
$thr  = function ( $tag ) { return Thread::where( 'title', A14_MARK . ' ' . $tag )->first(); };
$note = function ( $table, $id ) {
	$l   = get_option( A14_LEDGER, array() );
	$l[] = array( $table, (int) $id );
	update_option( A14_LEDGER, $l, false );
};
$new_feed = function ( $tag, $user_id, array $extra = array() ) {
	$slug = 'a14-probe-' . $tag . '-' . time();
	return Feed::create( array_merge( array(
		'user_id' => $user_id, 'title' => '', 'slug' => $slug, 'type' => 'text', 'content_type' => 'text',
		'privacy' => 'public', 'status' => 'published', 'meta' => array(),
		'message' => A14_MARK . ' ' . $tag, 'message_rendered' => '<p>' . A14_MARK . ' ' . $tag . '</p>',
	), $extra ) );
};

if ( 'mint' === $action ) {
	$out = array();
	foreach ( array( 'raju' => 'raju', 'student' => 'appza_test_student', 'logout' => 'appza_test_student' ) as $k => $login ) {
		$u = get_user_by( 'login', $login );
		$r = WP_Application_Passwords::create_new_application_password( $u->ID, array( 'name' => 'appza-a14-' . $k ) );
		if ( is_wp_error( $r ) ) {
			echo wp_json_encode( array( 'error' => $r->get_error_message() ) ) . "\n";
			return;
		}
		$out[ $k ] = $r[0];
		$out[ $k . '_uuid' ] = $r[1]['uuid'];
	}
	echo wp_json_encode( $out ) . "\n";
	return;
}

if ( 'revoke' === $action ) {
	$n = 0;
	foreach ( array( 1, $sid ) as $uid ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( $uid ) as $item ) {
			if ( 0 === strpos( $item['name'], 'appza-a14-' ) ) {
				WP_Application_Passwords::delete_application_password( $uid, $item['uuid'] );
				$n++;
			}
		}
	}
	echo wp_json_encode( array( 'revoked' => $n ) ) . "\n";
	return;
}

if ( 'transients' === $action ) {
	global $wpdb;
	echo wp_json_encode( array( 'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_appza\\_preview\\_%'" ) ) ) . "\n";
	return;
}

if ( 'create' === $action && ! $feed( 'main' ) ) {
	$main = $new_feed( 'main', 1 );
	$note( 'reaction', Reaction::create( array( 'user_id' => 1, 'object_id' => $main->id, 'object_type' => 'feed', 'type' => 'bookmark' ) )->id );
	$new_feed( 'scheduled', 1, array( 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ) ) );
	Moderation::create( array(
		'user_id' => $sid, 'post_id' => $main->id, 'reason' => A14_MARK . ' report',
		'explanation' => A14_MARK . ' report', 'content_type' => 'post', 'status' => 'pending',
	) );
	if ( ! Follow::where( 'follower_id', 1 )->where( 'followed_id', $sid )->exists() ) {
		$note( 'follow', Follow::create( array( 'follower_id' => 1, 'followed_id' => $sid, 'level' => 0 ) )->id );
	}

	// raju's own thread, the student not in it.
	$private = Thread::create( array( 'title' => A14_MARK . ' private', 'provider' => 'fcom', 'status' => 'active' ) );
	ThreadUser::create( array( 'thread_id' => $private->id, 'user_id' => 1, 'status' => 'active' ) );
	Message::create( array( 'thread_id' => $private->id, 'user_id' => 1, 'text' => A14_MARK . ' private message' ) );

	// A4: a post whose image sits inactive until an owner's edit brings it back.
	$a4 = $new_feed( 'a4', 1 );
	Media::create( array(
		'object_source' => 'feed', 'feed_id' => $a4->id, 'is_active' => 0, 'user_id' => 1,
		'media_type' => 'image/png', 'driver' => 'local', 'media_key' => md5( 'a14-probe-a4-' . time() ),
		'media_url' => home_url( '/wp-content/uploads/a14-probe-a4-' . time() . '.png' ), 'settings' => array(),
	) );

	// A5: raju's image the student must not attach, a DM they share, a group they left.
	Media::create( array(
		'object_source' => 'feed', 'user_id' => 1, 'is_active' => 1, 'driver' => 'local',
		'media_type' => 'image/png', 'media_key' => md5( 'a14-probe-a5-' . time() ), 'media_path' => '',
		'media_url' => home_url( '/wp-content/uploads/a14-probe-a5-' . time() . '.png' ), 'settings' => array(),
	) );
	$dm = Thread::create( array( 'title' => A14_MARK . ' dm', 'provider' => 'fcom', 'status' => 'active' ) );
	ThreadUser::create( array( 'thread_id' => $dm->id, 'user_id' => 1, 'status' => 'active' ) );
	ThreadUser::create( array( 'thread_id' => $dm->id, 'user_id' => $sid, 'status' => 'active' ) );
	$group = Thread::create( array( 'title' => A14_MARK . ' group', 'provider' => 'group', 'status' => 'active' ) );
	ThreadUser::create( array( 'thread_id' => $group->id, 'user_id' => 1, 'status' => 'active' ) );
	ThreadUser::create( array( 'thread_id' => $group->id, 'user_id' => $sid, 'status' => 'left' ) );
}

if ( 'a4-reset' === $action && ( $a4 = $feed( 'a4' ) ) ) {
	Media::where( 'media_url', 'like', '%/a14-probe-a4-%' )->update( array( 'is_active' => 0, 'feed_id' => $a4->id ) );
	$a4->meta = array();
	$a4->save();
}

if ( 'block' === $action || 'restore' === $action ) {
	$p = XProfile::where( 'user_id', $sid )->first();
	if ( 'block' === $action && false === get_option( A14_STATUS, false ) ) {
		update_option( A14_STATUS, $p->status, false );
	}
	$saved = get_option( A14_STATUS, false );
	$p->status = 'block' === $action ? 'blocked' : ( false === $saved ? $p->status : $saved );
	$p->save();
	if ( 'restore' === $action ) {
		delete_option( A14_STATUS );
	}
}

if ( 'cleanup' === $action ) {
	$n = array( 'feeds' => 0, 'threads' => 0, 'media' => 0, 'ledger' => 0, 'reports' => 0, 'comments' => 0 );
	foreach ( Feed::where( 'message', 'like', A14_MARK . '%' )->get() as $f ) {
		$n['reports']  += Moderation::where( 'post_id', $f->id )->delete();
		$n['comments'] += \FluentCommunity\App\Models\Comment::where( 'post_id', $f->id )->delete();
		$f->delete();
		$n['feeds']++;
	}
	foreach ( Thread::where( 'title', 'like', A14_MARK . '%' )->get() as $t ) {
		foreach ( Media::where( 'feed_id', $t->id )->whereIn( 'object_source', array( 'chat_message', 'chat_message_pending' ) )->where( 'user_id', $sid )->get() as $m ) {
			$m->delete();
		}
		Message::where( 'thread_id', $t->id )->delete();
		ThreadUser::where( 'thread_id', $t->id )->delete();
		$t->delete();
		$n['threads']++;
	}
	$n['media'] = Media::where( 'media_url', 'like', '%/a14-probe-%' )->delete();
	foreach ( get_option( A14_LEDGER, array() ) as $row ) {
		$n['ledger'] += 'follow' === $row[0] ? Follow::where( 'id', $row[1] )->delete() : Reaction::where( 'id', $row[1] )->delete();
	}
	delete_option( A14_LEDGER );
	echo wp_json_encode( $n ) . "\n";
	return;
}

$main = $feed( 'main' );
$a4   = $feed( 'a4' );
$a4m  = Media::where( 'media_url', 'like', '%/a14-probe-a4-%' )->first();
$a5m  = Media::where( 'media_url', 'like', '%/a14-probe-a5-%' )->first();
$meta = $a4 && is_array( $a4->meta ) ? $a4->meta : array();

// A locked lesson: a marked test course the student is not enrolled in.
global $wpdb;
$locked = $wpdb->get_row( $wpdb->prepare(
	"SELECT s.slug course_slug, l.slug lesson_slug FROM {$wpdb->prefix}fcom_spaces s
	 JOIN {$wpdb->prefix}fcom_posts l ON l.space_id = s.id AND l.type = 'course_lesson' AND l.status = 'published'
	 WHERE s.type = 'course' AND s.status = 'published' AND s.title LIKE 'APPZA-TEST-COURSE%%'
	 AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}fcom_space_user su WHERE su.space_id = s.id AND su.user_id = %d)
	 ORDER BY s.id LIMIT 1",
	$sid
), ARRAY_A );

echo wp_json_encode( array(
	'student_id'       => $sid,
	'student_status'   => (string) XProfile::where( 'user_id', $sid )->value( 'status' ),
	'main_id'          => $main ? (int) $main->id : 0,
	'private_thread'   => ( $t = $thr( 'private' ) ) ? (int) $t->id : 0,
	'dm_id'            => ( $t = $thr( 'dm' ) ) ? (int) $t->id : 0,
	'group_id'         => ( $t = $thr( 'group' ) ) ? (int) $t->id : 0,
	'a4_feed_id'       => $a4 ? (int) $a4->id : 0,
	'a4_media_url'     => $a4m ? $a4m->media_url : '',
	'a4_media_active'  => $a4m ? (int) $a4m->is_active : -1,
	'a4_meta_preview'  => isset( $meta['media_preview'] ) || isset( $meta['media_items'] ),
	'a5_key'           => $a5m ? $a5m->media_key : '',
	'a5_owner_source'  => $a5m ? (int) $a5m->user_id . '/' . $a5m->object_source : '',
	'community_space'  => (string) $wpdb->get_var( "SELECT slug FROM {$wpdb->prefix}fcom_spaces WHERE type = 'community' ORDER BY id LIMIT 1" ),
	'locked_course'    => $locked ? $locked['course_slug'] : '',
	'locked_lesson'    => $locked ? $locked['lesson_slug'] : '',
) ) . "\n";
