/**
 * DCW Guide Tools — finder behaviour.
 *
 * One card is visible at a time. Answering slides the track left; the progress
 * rail on the right jumps to any question already reached. When every question
 * is answered the matching result panel is revealed below.
 *
 * The scoring here mirrors includes/class-scorer.php exactly. Both read the
 * same config, so they cannot drift in what they compute — only in when they
 * run. PHP stays authoritative for anything server-side.
 */
( function () {
	'use strict';

	var SLIDE_DELAY = 260;

	/**
	 * Rank categories by score, breaking ties with the finder's declared order.
	 * Mirrors Scorer::rank().
	 */
	function rank( scores, tiebreak ) {
		var order = {};

		tiebreak.forEach( function ( key, index ) {
			order[ key ] = index;
		} );

		return Object.keys( scores ).sort( function ( a, b ) {
			if ( scores[ a ] !== scores[ b ] ) {
				return scores[ b ] - scores[ a ];
			}

			var pa = order[ a ] === undefined ? Number.MAX_SAFE_INTEGER : order[ a ];
			var pb = order[ b ] === undefined ? Number.MAX_SAFE_INTEGER : order[ b ];

			return pa - pb;
		} );
	}

	/**
	 * Score a set of answers. Mirrors Scorer::score().
	 */
	function score( config, answers ) {
		var scores = {};
		var eliminated = [];
		var notes = [];
		var answered = 0;

		Object.keys( config.categories ).forEach( function ( key ) {
			scores[ key ] = 0;
		} );

		config.questions.forEach( function ( question ) {
			var chosen = answers[ question.id ];

			if ( ! chosen ) {
				return;
			}

			var answer = question.answers.filter( function ( a ) {
				return a.id === chosen;
			} )[ 0 ];

			if ( ! answer ) {
				return;
			}

			answered++;

			Object.keys( answer.points || {} ).forEach( function ( category ) {
				if ( scores[ category ] !== undefined ) {
					scores[ category ] += parseInt( answer.points[ category ], 10 ) || 0;
				}
			} );

			( answer.eliminate || [] ).forEach( function ( category ) {
				if ( scores[ category ] !== undefined && eliminated.indexOf( category ) === -1 ) {
					eliminated.push( category );
				}
			} );

			if ( answer.note ) {
				notes.push( answer.note );
			}
		} );

		var ranked = rank( scores, config.tiebreak || [] );

		var survivors = ranked.filter( function ( key ) {
			return eliminated.indexOf( key ) === -1;
		} );

		// A gate that removes everything would leave the visitor with nothing;
		// fall back to the full ranking and let the note explain the caveat.
		if ( ! survivors.length ) {
			survivors = ranked;
		}

		var best = survivors[ 0 ] || null;
		var also = null;

		if ( best && survivors[ 1 ] ) {
			var runnerUp = survivors[ 1 ];
			var gap = scores[ best ] - scores[ runnerUp ];

			if ( gap <= config.window && scores[ runnerUp ] > 0 ) {
				also = runnerUp;
			}
		}

		return {
			complete: answered === config.questions.length,
			scores: scores,
			eliminated: eliminated,
			notes: notes,
			best: best,
			also: also
		};
	}

	function Finder( root ) {
		this.root = root;

		try {
			this.config = JSON.parse( root.getAttribute( 'data-config' ) || '{}' );
		} catch ( e ) {
			return;
		}

		if ( ! this.config.questions || ! this.config.questions.length ) {
			return;
		}

		this.track = root.querySelector( '[data-dcw-track]' );
		this.slides = Array.prototype.slice.call( root.querySelectorAll( '[data-dcw-slide]' ) );
		this.steps = Array.prototype.slice.call( root.querySelectorAll( '[data-dcw-step]' ) );
		this.resultStep = root.querySelector( '[data-dcw-step-result]' );
		this.results = root.querySelector( '[data-dcw-results]' );
		this.live = root.querySelector( '[data-dcw-live]' );

		this.answers = {};
		this.index = 0;
		this.furthest = 0;

		this.bind();
		this.root.classList.add( 'is-ready' );
		this.goTo( 0, { focus: false } );
	}

	Finder.prototype.bind = function () {
		var self = this;

		this.root.addEventListener( 'change', function ( event ) {
			var input = event.target.closest( '[data-dcw-answer]' );

			if ( input ) {
				self.onAnswer( input );
			}
		} );

		this.root.addEventListener( 'click', function ( event ) {
			var back = event.target.closest( '[data-dcw-back]' );

			if ( back ) {
				self.goTo( self.index - 1 );
				return;
			}

			var goto = event.target.closest( '[data-dcw-goto]' );

			if ( goto ) {
				self.goTo( parseInt( goto.getAttribute( 'data-dcw-goto' ), 10 ) );
				return;
			}

			if ( event.target.closest( '[data-dcw-goto-result]' ) ) {
				self.scrollToResults();
				return;
			}

			if ( event.target.closest( '[data-dcw-restart]' ) ) {
				self.restart();
			}
		} );
	};

	Finder.prototype.onAnswer = function ( input ) {
		var question = input.getAttribute( 'data-question' );

		this.answers[ question ] = input.value;

		this.furthest = Math.max( this.furthest, Math.min( this.index + 1, this.slides.length - 1 ) );

		this.syncSteps();

		var isLast = this.index === this.slides.length - 1;
		var self = this;

		if ( isLast ) {
			this.evaluate();
			return;
		}

		// A beat so the selected state is visible before the card moves.
		window.setTimeout( function () {
			self.goTo( self.index + 1 );
		}, SLIDE_DELAY );
	};

	Finder.prototype.goTo = function ( index, options ) {
		options = options || {};

		if ( index < 0 || index >= this.slides.length ) {
			return;
		}

		this.index = index;
		this.furthest = Math.max( this.furthest, index );

		this.track.style.transform = 'translateX(-' + ( index * 100 ) + '%)';

		// Offscreen slides must leave the tab order, or keyboard users tab into
		// questions they cannot see.
		this.slides.forEach( function ( slide, i ) {
			var hidden = i !== index;

			if ( 'inert' in HTMLElement.prototype ) {
				slide.inert = hidden;
			} else {
				slide.setAttribute( 'aria-hidden', hidden ? 'true' : 'false' );
			}
		} );

		var back = this.slides[ index ].querySelector( '[data-dcw-back]' );

		if ( back ) {
			back.hidden = index === 0;
		}

		this.syncSteps();

		if ( options.focus !== false ) {
			var title = this.slides[ index ].querySelector( '.dcw-finder__title' );

			if ( title ) {
				title.setAttribute( 'tabindex', '-1' );
				title.focus( { preventScroll: true } );
			}

			this.announce( this.config.questions[ index ].nav );
		}
	};

	Finder.prototype.syncSteps = function () {
		var self = this;

		this.steps.forEach( function ( step, i ) {
			var question = self.config.questions[ i ];
			var answered = !! self.answers[ question.id ];
			var button = step.querySelector( '[data-dcw-goto]' );
			var answerEl = step.querySelector( '[data-dcw-step-answer]' );

			step.classList.toggle( 'is-current', i === self.index );
			step.classList.toggle( 'is-answered', answered && i !== self.index );

			// Reachable: anywhere already visited, plus the next unanswered one.
			if ( button ) {
				button.disabled = i > self.furthest;
				button.setAttribute( 'aria-current', i === self.index ? 'step' : 'false' );
			}

			if ( answerEl ) {
				answerEl.textContent = answered ? self.answerLabel( question, self.answers[ question.id ] ) : '';
			}
		} );
	};

	Finder.prototype.answerLabel = function ( question, answerId ) {
		var match = question.answers.filter( function ( a ) {
			return a.id === answerId;
		} )[ 0 ];

		return match ? match.label : '';
	};

	Finder.prototype.evaluate = function () {
		var outcome = score( this.config, this.answers );

		if ( ! outcome.complete || ! outcome.best ) {
			return;
		}

		var panels = Array.prototype.slice.call( this.results.querySelectorAll( '[data-dcw-result]' ) );
		var active = null;

		panels.forEach( function ( panel ) {
			var isBest = panel.getAttribute( 'data-dcw-result' ) === outcome.best;

			panel.hidden = ! isBest;

			if ( isBest ) {
				active = panel;
			}
		} );

		if ( ! active ) {
			return;
		}

		this.results.hidden = false;
		this.decorate( active, outcome );
		this.fillForm( active, outcome );

		if ( this.resultStep ) {
			this.resultStep.classList.add( 'is-answered' );

			var resultBtn = this.resultStep.querySelector( '[data-dcw-goto-result]' );
			var status = this.resultStep.querySelector( '[data-dcw-result-status]' );

			if ( resultBtn ) {
				resultBtn.disabled = false;
			}

			if ( status ) {
				status.textContent = this.config.categories[ outcome.best ].label;
			}
		}

		this.announce( 'Your result: ' + this.config.categories[ outcome.best ].label );
		this.scrollToResults();
	};

	/**
	 * Fill in the parts of the panel that depend on the run: the gate note and
	 * the "also consider" block.
	 */
	Finder.prototype.decorate = function ( panel, outcome ) {
		var note = panel.querySelector( '[data-dcw-note]' );

		if ( note ) {
			var text = outcome.notes.join( ' ' );

			note.textContent = text;
			note.hidden = ! text;
		}

		var also = panel.querySelector( '[data-dcw-also]' );

		if ( ! also ) {
			return;
		}

		if ( ! outcome.also ) {
			also.hidden = true;
			return;
		}

		var category = this.config.categories[ outcome.also ];
		var title = also.querySelector( '[data-dcw-also-title]' );
		var desc = also.querySelector( '[data-dcw-also-desc]' );
		var compare = also.querySelector( '[data-dcw-compare]' );

		if ( title ) {
			title.textContent = category.label;
		}

		if ( desc ) {
			desc.textContent = category.consider;
		}

		if ( compare ) {
			compare.href = this.config.compareUrl + '?compare=' + encodeURIComponent( outcome.best + ',' + outcome.also );
		}

		also.hidden = false;
	};

	/**
	 * Attach the run to the Fluent Form so the specialist receives the answers
	 * alongside the lead. Hidden fields are optional — a form without them
	 * still submits normally.
	 */
	Finder.prototype.fillForm = function ( panel, outcome ) {
		var self = this;

		var readable = this.config.questions.map( function ( question ) {
			return question.nav + ': ' + self.answerLabel( question, self.answers[ question.id ] );
		} ).join( ' | ' );

		var values = {
			dcw_result: outcome.best,
			dcw_result_label: this.config.categories[ outcome.best ].label,
			dcw_also: outcome.also ? this.config.categories[ outcome.also ].label : '',
			dcw_answers: readable,
			dcw_scores: Object.keys( outcome.scores ).map( function ( key ) {
				return key + '=' + outcome.scores[ key ];
			} ).join( ',' )
		};

		Object.keys( values ).forEach( function ( name ) {
			var field = panel.querySelector( '[name="' + name + '"]' );

			if ( ! field ) {
				return;
			}

			field.value = values[ name ];
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	};

	Finder.prototype.scrollToResults = function () {
		if ( this.results.hidden ) {
			return;
		}

		var reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		this.results.scrollIntoView( {
			behavior: reduced ? 'auto' : 'smooth',
			block: 'start'
		} );
	};

	Finder.prototype.restart = function () {
		var self = this;

		this.answers = {};
		this.furthest = 0;

		this.root.querySelectorAll( '[data-dcw-answer]' ).forEach( function ( input ) {
			input.checked = false;
		} );

		this.results.hidden = true;

		this.results.querySelectorAll( '[data-dcw-result]' ).forEach( function ( panel ) {
			panel.hidden = true;
		} );

		if ( this.resultStep ) {
			this.resultStep.classList.remove( 'is-answered' );

			var resultBtn = this.resultStep.querySelector( '[data-dcw-goto-result]' );

			if ( resultBtn ) {
				resultBtn.disabled = true;
			}
		}

		this.goTo( 0 );

		window.setTimeout( function () {
			self.root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}, 60 );
	};

	Finder.prototype.announce = function ( message ) {
		if ( this.live ) {
			this.live.textContent = message;
		}
	};

	function init() {
		document.querySelectorAll( '[data-dcw-finder]' ).forEach( function ( root ) {
			if ( ! root.dataset.dcwBooted ) {
				root.dataset.dcwBooted = '1';
				new Finder( root );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Bricks re-renders elements in the builder canvas without a page load.
	document.addEventListener( 'bricks/ajax/end', init );
} )();
