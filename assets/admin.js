/**
 * Finder admin behaviour.
 *
 * Three jobs: the accordion, adding/removing questions and answers, and the
 * test-drive rail. The rail reads the live form rather than saved data, so it
 * scores what is on screen right now — that is the whole point of having it
 * next to the editor.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.dcwa' );

	if ( ! root ) {
		return;
	}

	var form = root.querySelector( '[data-dcwa-form]' );

	// ------------------------------------------------------------- helpers

	function slugify( text ) {
		return String( text )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 60 );
	}

	/**
	 * Rewrite dcw[questions][N]... indices so posted arrays stay in document
	 * order after inserts and deletes.
	 */
	function reindex() {
		var cards = root.querySelectorAll( '[data-dcwa-questions] [data-dcwa-card]' );

		cards.forEach( function ( card, qi ) {
			var num = card.querySelector( '[data-dcwa-num]' );

			if ( num ) {
				num.textContent = String( qi + 1 );
			}

			card.querySelectorAll( '[data-dcwa-row]' ).forEach( function ( row, ri ) {
				row.querySelectorAll( '[name]' ).forEach( function ( field ) {
					field.name = field.name
						.replace( /dcw\[questions\]\[\d+\]/, 'dcw[questions][' + qi + ']' )
						.replace( /\[answers\]\[\d+\]/, '[answers][' + ri + ']' );
				} );
			} );

			// Fields directly on the card (title, id, type, nav).
			card.querySelectorAll( '.dcwa-card__head [name]' ).forEach( function ( field ) {
				field.name = field.name.replace( /dcw\[questions\]\[\d+\]/, 'dcw[questions][' + qi + ']' );
			} );
		} );
	}

	function cardOf( el ) {
		return el.closest( '[data-dcwa-card]' );
	}

	// ----------------------------------------------------------- accordion

	/**
	 * Single place that opens or closes a card, so the class, the button's
	 * aria-expanded and the title's editability can never drift apart.
	 */
	function setOpen( card, open ) {
		card.classList.toggle( 'is-open', open );

		var toggle = card.querySelector( '[data-dcwa-toggle]' );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		// The heading is only a field while the card is open. `readonly`
		// rather than `disabled`, because disabled controls are not posted
		// and every collapsed question would lose its title on save.
		var title = card.querySelector( '.dcwa-card__title' );

		if ( title ) {
			title.readOnly = ! open;
			title.tabIndex = open ? 0 : -1;
		}
	}

	// ---------------------------------------------------------------- help

	var helpBtn = root.querySelector( '[data-dcwa-help]' );
	var helpPanel = root.querySelector( '[data-dcwa-help-panel]' );

	function setHelp( open ) {
		if ( ! helpBtn || ! helpPanel ) {
			return;
		}

		helpBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		helpPanel.hidden = ! open;
	}

	if ( helpBtn ) {
		helpBtn.addEventListener( 'click', function () {
			setHelp( 'true' !== helpBtn.getAttribute( 'aria-expanded' ) );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && 'true' === helpBtn.getAttribute( 'aria-expanded' ) ) {
				setHelp( false );
				helpBtn.focus();
			}
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest( '[data-dcwa-toggle]' );

		if ( toggle ) {
			var card = cardOf( toggle );

			setOpen( card, ! card.classList.contains( 'is-open' ) );
			return;
		}

		// ------------------------------------------------------ delete row

		var delRow = event.target.closest( '[data-dcwa-delete-row]' );

		if ( delRow ) {
			var row = delRow.closest( '[data-dcwa-row]' );
			var rows = row.parentElement;

			if ( rows.querySelectorAll( '[data-dcwa-row]:not(.dcwa-row--note)' ).length <= 1 ) {
				window.alert( 'A question needs at least one answer. Delete the question instead.' );
				return;
			}

			// Gate rows carry a following note row that belongs to them.
			var next = row.nextElementSibling;

			if ( next && next.classList.contains( 'dcwa-row--note' ) ) {
				next.remove();
			}

			row.remove();
			reindex();
			buildTest();
			return;
		}

		// ------------------------------------------------- delete question

		var delQ = event.target.closest( '[data-dcwa-delete-question]' );

		if ( delQ ) {
			var qcard = cardOf( delQ );
			var title = qcard.querySelector( '.dcwa-card__title' );
			var label = title && title.value ? '“' + title.value + '”' : 'this question';

			if ( ! window.confirm( 'Delete ' + label + '? This cannot be undone once you save.' ) ) {
				return;
			}

			qcard.remove();
			reindex();
			buildTest();
			return;
		}

		// ---------------------------------------------------- add answer

		var addA = event.target.closest( '[data-dcwa-add-answer]' );

		if ( addA ) {
			var target = cardOf( addA );
			var isGate = target.querySelector( '.dcwa-matrix--gate' );
			var tpl = root.querySelector( '[data-dcwa-template="' + ( isGate ? 'answer-gate' : 'answer' ) + '"]' );

			target.querySelector( '[data-dcwa-rows]' ).appendChild( tpl.content.cloneNode( true ) );
			reindex();
			buildTest();
			return;
		}

		// -------------------------------------------------- add question

		var addQ = event.target.closest( '[data-dcwa-add-question]' );

		if ( addQ ) {
			var list = root.querySelector( '[data-dcwa-questions]' );
			var qtpl = root.querySelector( '[data-dcwa-template="question"]' );

			list.appendChild( qtpl.content.cloneNode( true ) );
			reindex();

			var added = list.lastElementChild;

			setOpen( added, true );

			var field = added.querySelector( '.dcwa-card__title' );

			if ( field ) {
				field.focus();
			}

			buildTest();
			return;
		}

		if ( event.target.closest( '[data-dcwa-reset]' ) ) {
			buildTest();
			return;
		}

		// ------------------------------------------- click the card itself

		// Runs last, so every button above has already claimed its own click
		// and returned. Closed, the whole card opens it; open, only the
		// header row closes it, leaving the matrix free to be worked in.
		// Anything that is itself a control keeps its click, and a drag that
		// selected text is not treated as one.
		var hit = cardOf( event.target );

		if ( ! hit || event.target.closest( 'input, textarea, select, button, label, a' ) ) {
			return;
		}

		var selection = window.getSelection();

		if ( selection && ! selection.isCollapsed ) {
			return;
		}

		var isOpen = hit.classList.contains( 'is-open' );

		if ( ! isOpen || event.target.closest( '.dcwa-card__head' ) ) {
			setOpen( hit, ! isOpen );
		}
	} );

	// ------------------------------------------------- live field feedback

	root.addEventListener( 'input', function ( event ) {
		if ( event.target.matches( '[data-dcwa-points]' ) ) {
			highlightRow( event.target.closest( '[data-dcwa-row]' ) );
			scheduleScore();
		}

		if ( event.target.matches( '.dcwa-card__title' ) ) {
			// Keep the short nav label in step with the question until someone
			// deliberately gives it its own wording.
			var card = cardOf( event.target );
			var nav = card.querySelector( '[data-dcwa-nav]' );

			if ( nav && ! nav.dataset.touched ) {
				nav.value = event.target.value;
			}

			buildTest();
		}

		if ( event.target.matches( '.dcwa-row__label' ) ) {
			buildTest();
		}
	} );

	root.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '[type="checkbox"]' ) ) {
			scheduleScore();
		}
	} );

	/**
	 * Mark the highest-scoring cell in a row so the answer's bias is readable.
	 */
	function highlightRow( row ) {
		if ( ! row ) {
			return;
		}

		var cells = Array.prototype.slice.call( row.querySelectorAll( '[data-dcwa-points]' ) );
		var best = 0;

		cells.forEach( function ( cell ) {
			best = Math.max( best, parseInt( cell.value, 10 ) || 0 );
		} );

		cells.forEach( function ( cell ) {
			var value = parseInt( cell.value, 10 ) || 0;

			cell.classList.toggle( 'is-best', value > 0 && value === best );
			cell.classList.toggle( 'is-zero', 0 === value );
		} );
	}

	// ------------------------------------------------------- test drive

	var testWrap = root.querySelector( '[data-dcwa-test]' );
	var outcome = root.querySelector( '[data-dcwa-outcome]' );
	var timer = null;

	function scheduleScore() {
		window.clearTimeout( timer );
		timer = window.setTimeout( score, 120 );
	}

	/**
	 * Read the current editor state into the same shape the scorer expects.
	 */
	function readModel() {
		var categories = [];

		// Every question repeats the same category columns, so read them from
		// the first matrix only — querying the whole screen would return one
		// set per question and render four bars per card.
		var head = root.querySelector( '.dcwa-matrix__head' );

		if ( head ) {
			head.querySelectorAll( '.dcwa-matrix__col' ).forEach( function ( col ) {
				categories.push( {
					key: col.getAttribute( 'data-dcwa-cat' ) || '',
					label: col.textContent.trim(),
					color: col.querySelector( '.dcwa-dot' ) ? col.querySelector( '.dcwa-dot' ).style.background : '#999'
				} );
			} );
		}

		var questions = [];

		root.querySelectorAll( '[data-dcwa-questions] [data-dcwa-card]' ).forEach( function ( card ) {
			var title = card.querySelector( '.dcwa-card__title' );

			if ( ! title || ! title.value.trim() ) {
				return;
			}

			var isGate = !! card.querySelector( '.dcwa-matrix--gate' );
			var answers = [];

			card.querySelectorAll( '[data-dcwa-row]:not(.dcwa-row--note)' ).forEach( function ( row ) {
				var label = row.querySelector( '.dcwa-row__label' );

				if ( ! label || ! label.value.trim() ) {
					return;
				}

				var answer = { label: label.value.trim(), points: [], eliminate: [] };

				if ( isGate ) {
					row.querySelectorAll( '[type="checkbox"]' ).forEach( function ( box, i ) {
						if ( box.checked ) {
							answer.eliminate.push( i );
						}
					} );
				} else {
					row.querySelectorAll( '[data-dcwa-points]' ).forEach( function ( cell ) {
						answer.points.push( parseInt( cell.value, 10 ) || 0 );
					} );
				}

				answers.push( answer );
			} );

			if ( answers.length ) {
				questions.push( { title: title.value.trim(), gate: isGate, answers: answers } );
			}
		} );

		// The chip posts the declared order as hidden inputs. Reading it here
		// keeps the rail honest: without it the rail broke ties by column
		// order, which silently agreed with the front end only because the
		// two happened to match.
		var tiebreak = [];

		root.querySelectorAll( 'input[name="dcw[tiebreak][]"]' ).forEach( function ( input ) {
			tiebreak.push( input.value );
		} );

		return { categories: categories, questions: questions, tiebreak: tiebreak };
	}

	function buildTest() {
		if ( ! testWrap ) {
			return;
		}

		var model = readModel();
		var previous = {};

		testWrap.querySelectorAll( 'select' ).forEach( function ( select ) {
			previous[ select.dataset.q ] = select.value;
		} );

		testWrap.innerHTML = '';

		model.questions.forEach( function ( question, qi ) {
			var wrap = document.createElement( 'label' );

			wrap.className = 'dcwa-test__field';

			var name = document.createElement( 'span' );

			name.textContent = question.title;
			wrap.appendChild( name );

			var select = document.createElement( 'select' );

			select.dataset.q = String( qi );

			var blank = document.createElement( 'option' );

			blank.value = '';
			blank.textContent = '—';
			select.appendChild( blank );

			question.answers.forEach( function ( answer, ai ) {
				var option = document.createElement( 'option' );

				option.value = String( ai );
				option.textContent = answer.label;
				select.appendChild( option );
			} );

			if ( previous[ qi ] !== undefined ) {
				select.value = previous[ qi ];
			}

			select.addEventListener( 'change', score );
			wrap.appendChild( select );
			testWrap.appendChild( wrap );
		} );

		score();
	}

	/**
	 * Same algorithm as the front end: points, gate eliminations, rank, then
	 * always offer the next-best survivor.
	 */
	function score() {
		if ( ! outcome ) {
			return;
		}

		var model = readModel();
		var totals = model.categories.map( function () {
			return 0;
		} );
		var eliminated = {};
		var answered = 0;

		model.questions.forEach( function ( question, qi ) {
			var select = testWrap.querySelector( 'select[data-q="' + qi + '"]' );

			if ( ! select || '' === select.value ) {
				return;
			}

			var answer = question.answers[ parseInt( select.value, 10 ) ];

			if ( ! answer ) {
				return;
			}

			answered++;

			answer.points.forEach( function ( points, ci ) {
				totals[ ci ] += points;
			} );

			answer.eliminate.forEach( function ( ci ) {
				eliminated[ ci ] = true;
			} );
		} );

		if ( ! model.questions.length || answered !== model.questions.length ) {
			outcome.hidden = true;
			return;
		}

		// Mirrors Scorer::rank(): points first, then the declared tie-break
		// order, then column order for anything the order does not name.
		var rankOf = {};

		model.tiebreak.forEach( function ( key, i ) {
			rankOf[ key ] = i;
		} );

		var order = model.categories.map( function ( category, i ) {
			return i;
		} );

		order.sort( function ( a, b ) {
			if ( totals[ a ] !== totals[ b ] ) {
				return totals[ b ] - totals[ a ];
			}

			var ra = rankOf[ model.categories[ a ].key ];
			var rb = rankOf[ model.categories[ b ].key ];

			ra = ra === undefined ? Number.MAX_SAFE_INTEGER : ra;
			rb = rb === undefined ? Number.MAX_SAFE_INTEGER : rb;

			return ra - rb || a - b;
		} );

		var survivors = order.filter( function ( i ) {
			return ! eliminated[ i ];
		} );

		if ( ! survivors.length ) {
			survivors = order;
		}

		var best = survivors[ 0 ];
		var also = survivors[ 1 ];
		var max = Math.max.apply( null, totals.concat( [ 1 ] ) );

		outcome.hidden = false;
		outcome.querySelector( '[data-dcwa-outcome-dot]' ).style.background = model.categories[ best ].color;
		outcome.querySelector( '[data-dcwa-outcome-name]' ).textContent = model.categories[ best ].label;
		outcome.querySelector( '[data-dcwa-outcome-pts]' ).textContent = totals[ best ] + ' pts';

		var bars = outcome.querySelector( '[data-dcwa-bars]' );

		bars.innerHTML = '';

		order.forEach( function ( i ) {
			var bar = document.createElement( 'div' );

			bar.className = 'dcwa-bar' + ( i === best ? ' is-best' : '' );
			bar.innerHTML =
				'<span class="dcwa-bar__name"></span>' +
				'<span class="dcwa-bar__track"><span class="dcwa-bar__fill"></span></span>' +
				'<span class="dcwa-bar__val"></span>';

			bar.querySelector( '.dcwa-bar__name' ).textContent = model.categories[ i ].label;
			bar.querySelector( '.dcwa-bar__val' ).textContent = String( totals[ i ] );

			var fill = bar.querySelector( '.dcwa-bar__fill' );

			fill.style.width = Math.round( ( totals[ i ] / max ) * 100 ) + '%';
			fill.style.background = model.categories[ i ].color;

			if ( eliminated[ i ] ) {
				fill.style.opacity = '0.35';
				bar.querySelector( '.dcwa-bar__name' ).style.textDecoration = 'line-through';
			}

			bars.appendChild( bar );
		} );

		var note = outcome.querySelector( '[data-dcwa-outcome-note]' );
		var parts = [];

		if ( Object.keys( eliminated ).length ) {
			parts.push(
				'Ruled out by the gate: ' +
					Object.keys( eliminated ).map( function ( i ) {
						return model.categories[ i ].label;
					} ).join( ', ' ) + '.'
			);
		}

		if ( undefined !== also ) {
			parts.push( model.categories[ also ].label + ' is shown as “Also consider”.' );
		}

		note.textContent = parts.join( ' ' );
	}

	// Someone editing the nav label by hand should keep it.
	root.querySelectorAll( '[data-dcwa-nav]' ).forEach( function ( nav ) {
		if ( nav.value ) {
			nav.dataset.touched = '1';
		}
	} );

	// Guard against losing edits to a stray click.
	var dirty = false;

	root.addEventListener( 'input', function () {
		dirty = true;
	} );

	if ( form ) {
		form.addEventListener( 'submit', function () {
			dirty = false;
			reindex();
		} );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	buildTest();
} )();
