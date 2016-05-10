<?php

/**
 * This is the part of the wikitext parser which handles automatic paragraphs
 * and conversion of start-of-line prefixes to HTML lists.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Parser
 */
class BlockLevelPass {
	private $mDTopen = false;
	private $mInPre = false;
	private $mLastSection = '';
	private $linestart;
	private $text;

	# State constants for the definition list colon extraction
	const COLON_STATE_TEXT = 0;
	const COLON_STATE_TAG = 1;
	const COLON_STATE_TAGSTART = 2;
	const COLON_STATE_CLOSETAG = 3;
	const COLON_STATE_TAGSLASH = 4;
	const COLON_STATE_COMMENT = 5;
	const COLON_STATE_COMMENTDASH = 6;
	const COLON_STATE_COMMENTDASHDASH = 7;

	/**
	 * Make lists from lines starting with ':', '*', '#', etc.
	 *
	 * @param string $text
	 * @param bool $linestart Whether or not this is at the start of a line.
	 * @return string The lists rendered as HTML
	 */
	public static function doBlockLevels( $text, $linestart ) {
		$pass = new self( $text, $linestart );
		return $pass->execute();
	}

	private function __construct( $text, $linestart ) {
		$this->text = $text;
		$this->linestart = $linestart;
	}

	/**
	 * @return string
	 */
	private function closeParagraph() {
		$result = '';
		if ( $this->mLastSection != '' ) {
			$result = '</' . $this->mLastSection . ">\n";
		}
		$this->mInPre = false;
		$this->mLastSection = '';
		return $result;
	}

	/**
	 * getCommon() returns the length of the longest common substring
	 * of both arguments, starting at the beginning of both.
	 *
	 * @param string $st1
	 * @param string $st2
	 *
	 * @return int
	 */
	private function getCommon( $st1, $st2 ) {
		$fl = strlen( $st1 );
		$shorter = strlen( $st2 );
		if ( $fl < $shorter ) {
			$shorter = $fl;
		}

		for ( $i = 0; $i < $shorter; ++$i ) {
			if ( $st1[$i] != $st2[$i] ) {
				break;
			}
		}
		return $i;
	}

	/**
	 * These next three functions open, continue, and close the list
	 * element appropriate to the prefix character passed into them.
	 *
	 * @param string $char
	 *
	 * @return string
	 */
	private function openList( $char ) {
		$result = $this->closeParagraph();

		if ( '*' === $char ) {
			$result .= "<ul><li>";
		} elseif ( '#' === $char ) {
			$result .= "<ol><li>";
		} elseif ( ':' === $char ) {
			$result .= "<dl><dd>";
		} elseif ( ';' === $char ) {
			$result .= "<dl><dt>";
			$this->mDTopen = true;
		} else {
			$result = '<!-- ERR 1 -->';
		}

		return $result;
	}

	/**
	 * TODO: document
	 * @param string $char
	 *
	 * @return string
	 */
	private function nextItem( $char ) {
		if ( '*' === $char || '#' === $char ) {
			return "</li>\n<li>";
		} elseif ( ':' === $char || ';' === $char ) {
			$close = "</dd>\n";
			if ( $this->mDTopen ) {
				$close = "</dt>\n";
			}
			if ( ';' === $char ) {
				$this->mDTopen = true;
				return $close . '<dt>';
			} else {
				$this->mDTopen = false;
				return $close . '<dd>';
			}
		}
		return '<!-- ERR 2 -->';
	}

	/**
	 * @todo Document
	 * @param string $char
	 *
	 * @return string
	 */
	private function closeList( $char ) {
		if ( '*' === $char ) {
			$text = "</li></ul>";
		} elseif ( '#' === $char ) {
			$text = "</li></ol>";
		} elseif ( ':' === $char ) {
			if ( $this->mDTopen ) {
				$this->mDTopen = false;
				$text = "</dt></dl>";
			} else {
				$text = "</dd></dl>";
			}
		} else {
			return '<!-- ERR 3 -->';
		}
		return $text;
	}
	/**#@-*/

	private function execute() {
		$text = $this->text;
		# Parsing through the text line by line.  The main thing
		# happening here is handling of block-level elements p, pre,
		# and making lists from lines starting with * # : etc.
		$textLines = StringUtils::explode( "\n", $text );

		$lastPrefix = $output = '';
		$this->mDTopen = $inBlockElem = false;
		$prefixLength = 0;
		$paragraphStack = false;
		$inBlockquote = false;

		foreach ( $textLines as $oLine ) {
			# Fix up $linestart
			if ( !$this->linestart ) {
				$output .= $oLine;
				$this->linestart = true;
				continue;
			}
			# * = ul
			# # = ol
			# ; = dt
			# : = dd

			$lastPrefixLength = strlen( $lastPrefix );
			$preCloseMatch = preg_match( '/<\\/pre/i', $oLine );
			$preOpenMatch = preg_match( '/<pre/i', $oLine );
			# If not in a <pre> element, scan for and figure out what prefixes are there.
			if ( !$this->mInPre ) {
				# Multiple prefixes may abut each other for nested lists.
				$prefixLength = strspn( $oLine, '*#:;' );
				$prefix = substr( $oLine, 0, $prefixLength );

				# eh?
				# ; and : are both from definition-lists, so they're equivalent
				#  for the purposes of determining whether or not we need to open/close
				#  elements.
				$prefix2 = str_replace( ';', ':', $prefix );
				$t = substr( $oLine, $prefixLength );
				$this->mInPre = (bool)$preOpenMatch;
			} else {
				# Don't interpret any other prefixes in preformatted text
				$prefixLength = 0;
				$prefix = $prefix2 = '';
				$t = $oLine;
			}

			# List generation
			if ( $prefixLength && $lastPrefix === $prefix2 ) {
				# Same as the last item, so no need to deal with nesting or opening stuff
				$output .= $this->nextItem( substr( $prefix, -1 ) );
				$paragraphStack = false;

				if ( substr( $prefix, -1 ) === ';' ) {
					# The one nasty exception: definition lists work like this:
					# ; title : definition text
					# So we check for : in the remainder text to split up the
					# title and definition, without b0rking links.
					$term = $t2 = '';
					if ( $this->findColonNoLinks( $t, $term, $t2 ) !== false ) {
						$t = $t2;
						$output .= $term . $this->nextItem( ':' );
					}
				}
			} elseif ( $prefixLength || $lastPrefixLength ) {
				# We need to open or close prefixes, or both.

				# Either open or close a level...
				$commonPrefixLength = $this->getCommon( $prefix, $lastPrefix );
				$paragraphStack = false;

				# Close all the prefixes which aren't shared.
				while ( $commonPrefixLength < $lastPrefixLength ) {
					$output .= $this->closeList( $lastPrefix[$lastPrefixLength - 1] );
					--$lastPrefixLength;
				}

				# Continue the current prefix if appropriate.
				if ( $prefixLength <= $commonPrefixLength && $commonPrefixLength > 0 ) {
					$output .= $this->nextItem( $prefix[$commonPrefixLength - 1] );
				}

				# Open prefixes where appropriate.
				if ( $lastPrefix && $prefixLength > $commonPrefixLength ) {
					$output .= "\n";
				}
				while ( $prefixLength > $commonPrefixLength ) {
					$char = substr( $prefix, $commonPrefixLength, 1 );
					$output .= $this->openList( $char );

					if ( ';' === $char ) {
						# @todo FIXME: This is dupe of code above
						if ( $this->findColonNoLinks( $t, $term, $t2 ) !== false ) {
							$t = $t2;
							$output .= $term . $this->nextItem( ':' );
						}
					}
					++$commonPrefixLength;
				}
				if ( !$prefixLength && $lastPrefix ) {
					$output .= "\n";
				}
				$lastPrefix = $prefix2;
			}

			# If we have no prefixes, go to paragraph mode.
			if ( 0 == $prefixLength ) {
				# No prefix (not in list)--go to paragraph mode
				# XXX: use a stack for nestable elements like span, table and div
				$openmatch = preg_match(
					'/(?:<table|<h1|<h2|<h3|<h4|<h5|<h6|<pre|<tr|'
						. '<p|<ul|<ol|<dl|<li|<\\/tr|<\\/td|<\\/th)/iS',
					$t
				);
				$closematch = preg_match(
					'/(?:<\\/table|<\\/h1|<\\/h2|<\\/h3|<\\/h4|<\\/h5|<\\/h6|'
						. '<td|<th|<\\/?blockquote|<\\/?div|<hr|<\\/pre|<\\/p|<\\/mw:|'
						. Parser::MARKER_PREFIX
						. '-pre|<\\/li|<\\/ul|<\\/ol|<\\/dl|<\\/?center)/iS',
					$t
				);

				if ( $openmatch || $closematch ) {
					$paragraphStack = false;
					# @todo bug 5718: paragraph closed
					$output .= $this->closeParagraph();
					if ( $preOpenMatch && !$preCloseMatch ) {
						$this->mInPre = true;
					}
					$bqOffset = 0;
					while ( preg_match( '/<(\\/?)blockquote[\s>]/i', $t,
						$bqMatch, PREG_OFFSET_CAPTURE, $bqOffset )
					) {
						$inBlockquote = !$bqMatch[1][0]; // is this a close tag?
						$bqOffset = $bqMatch[0][1] + strlen( $bqMatch[0][0] );
					}
					$inBlockElem = !$closematch;
				} elseif ( !$inBlockElem && !$this->mInPre ) {
					if ( ' ' == substr( $t, 0, 1 )
						&& ( $this->mLastSection === 'pre' || trim( $t ) != '' )
						&& !$inBlockquote
					) {
						# pre
						if ( $this->mLastSection !== 'pre' ) {
							$paragraphStack = false;
							$output .= $this->closeParagraph() . '<pre>';
							$this->mLastSection = 'pre';
						}
						$t = substr( $t, 1 );
					} else {
						# paragraph
						if ( trim( $t ) === '' ) {
							if ( $paragraphStack ) {
								$output .= $paragraphStack . '<br />';
								$paragraphStack = false;
								$this->mLastSection = 'p';
							} else {
								if ( $this->mLastSection !== 'p' ) {
									$output .= $this->closeParagraph();
									$this->mLastSection = '';
									$paragraphStack = '<p>';
								} else {
									$paragraphStack = '</p><p>';
								}
							}
						} else {
							if ( $paragraphStack ) {
								$output .= $paragraphStack;
								$paragraphStack = false;
								$this->mLastSection = 'p';
							} elseif ( $this->mLastSection !== 'p' ) {
								$output .= $this->closeParagraph() . '<p>';
								$this->mLastSection = 'p';
							}
						}
					}
				}
			}
			# somewhere above we forget to get out of pre block (bug 785)
			if ( $preCloseMatch && $this->mInPre ) {
				$this->mInPre = false;
			}
			if ( $paragraphStack === false ) {
				$output .= $t;
				if ( $prefixLength === 0 ) {
					$output .= "\n";
				}
			}
		}
		while ( $prefixLength ) {
			$output .= $this->closeList( $prefix2[$prefixLength - 1] );
			--$prefixLength;
			if ( !$prefixLength ) {
				$output .= "\n";
			}
		}
		if ( $this->mLastSection != '' ) {
			$output .= '</' . $this->mLastSection . '>';
			$this->mLastSection = '';
		}

		return $output;
	}

	/**
	 * Split up a string on ':', ignoring any occurrences inside tags
	 * to prevent illegal overlapping.
	 *
	 * @param string $str The string to split
	 * @param string &$before Set to everything before the ':'
	 * @param string &$after Set to everything after the ':'
	 * @throws MWException
	 * @return string The position of the ':', or false if none found
	 */
	private function findColonNoLinks( $str, &$before, &$after ) {
		$pos = strpos( $str, ':' );
		if ( $pos === false ) {
			# Nothing to find!
			return false;
		}

		$lt = strpos( $str, '<' );
		if ( $lt === false || $lt > $pos ) {
			# Easy; no tag nesting to worry about
			$before = substr( $str, 0, $pos );
			$after = substr( $str, $pos + 1 );
			return $pos;
		}

		# Ugly state machine to walk through avoiding tags.
		$state = self::COLON_STATE_TEXT;
		$stack = 0;
		$len = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $str[$i];

			switch ( $state ) {
			# (Using the number is a performance hack for common cases)
			case 0: # self::COLON_STATE_TEXT:
				switch ( $c ) {
				case "<":
					# Could be either a <start> tag or an </end> tag
					$state = self::COLON_STATE_TAGSTART;
					break;
				case ":":
					if ( $stack == 0 ) {
						# We found it!
						$before = substr( $str, 0, $i );
						$after = substr( $str, $i + 1 );
						return $i;
					}
					# Embedded in a tag; don't break it.
					break;
				default:
					# Skip ahead looking for something interesting
					$colon = strpos( $str, ':', $i );
					if ( $colon === false ) {
						# Nothing else interesting
						return false;
					}
					$lt = strpos( $str, '<', $i );
					if ( $stack === 0 ) {
						if ( $lt === false || $colon < $lt ) {
							# We found it!
							$before = substr( $str, 0, $colon );
							$after = substr( $str, $colon + 1 );
							return $i;
						}
					}
					if ( $lt === false ) {
						# Nothing else interesting to find; abort!
						# We're nested, but there's no close tags left. Abort!
						break 2;
					}
					# Skip ahead to next tag start
					$i = $lt;
					$state = self::COLON_STATE_TAGSTART;
				}
				break;
			case 1: # self::COLON_STATE_TAG:
				# In a <tag>
				switch ( $c ) {
				case ">":
					$stack++;
					$state = self::COLON_STATE_TEXT;
					break;
				case "/":
					# Slash may be followed by >?
					$state = self::COLON_STATE_TAGSLASH;
					break;
				default:
					# ignore
				}
				break;
			case 2: # self::COLON_STATE_TAGSTART:
				switch ( $c ) {
				case "/":
					$state = self::COLON_STATE_CLOSETAG;
					break;
				case "!":
					$state = self::COLON_STATE_COMMENT;
					break;
				case ">":
					# Illegal early close? This shouldn't happen D:
					$state = self::COLON_STATE_TEXT;
					break;
				default:
					$state = self::COLON_STATE_TAG;
				}
				break;
			case 3: # self::COLON_STATE_CLOSETAG:
				# In a </tag>
				if ( $c === ">" ) {
					$stack--;
					if ( $stack < 0 ) {
						wfDebug( __METHOD__ . ": Invalid input; too many close tags\n" );
						return false;
					}
					$state = self::COLON_STATE_TEXT;
				}
				break;
			case self::COLON_STATE_TAGSLASH:
				if ( $c === ">" ) {
					# Yes, a self-closed tag <blah/>
					$state = self::COLON_STATE_TEXT;
				} else {
					# Probably we're jumping the gun, and this is an attribute
					$state = self::COLON_STATE_TAG;
				}
				break;
			case 5: # self::COLON_STATE_COMMENT:
				if ( $c === "-" ) {
					$state = self::COLON_STATE_COMMENTDASH;
				}
				break;
			case self::COLON_STATE_COMMENTDASH:
				if ( $c === "-" ) {
					$state = self::COLON_STATE_COMMENTDASHDASH;
				} else {
					$state = self::COLON_STATE_COMMENT;
				}
				break;
			case self::COLON_STATE_COMMENTDASHDASH:
				if ( $c === ">" ) {
					$state = self::COLON_STATE_TEXT;
				} else {
					$state = self::COLON_STATE_COMMENT;
				}
				break;
			default:
				throw new MWException( "State machine error in " . __METHOD__ );
			}
		}
		if ( $stack > 0 ) {
			wfDebug( __METHOD__ . ": Invalid input; not enough close tags (stack $stack, state $state)\n" );
			return false;
		}
		return false;
	}
}
