<?php
// Dev only: route all WordPress mail to the Mailpit container (http://localhost:8025).
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$phpmailer->isSMTP();
	$phpmailer->Host     = 'mailpit';
	$phpmailer->Port     = 1025;
	$phpmailer->SMTPAuth = false;
} );
