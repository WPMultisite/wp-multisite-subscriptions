<?php

namespace WP_Ultimo;

class Lazy_Phrase {
	private string $phrase;
	private string $domain;
	private string $context;

	public function __construct( string $phrase, string $domain = 'default', string $context = '' ) {
        $this->phrase = $phrase;
        $this->domain = $domain;
        $this->context = $context;
    }

	public function __toString() {
		return \translate( $this->phrase, $this->domain );
	}
}