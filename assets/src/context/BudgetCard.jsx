/**
 * What this context costs: a total, and a breakdown by part.
 *
 * No ceiling, and no meter. An earlier version drew a bar against 600 tokens,
 * which came from measuring what the payload costs rather than from anything
 * that happens at 600. Measured on a real site the whole context block is 433
 * tokens against the ability list's 4,411 in the same response, and the response
 * as a whole is 2.4% of a 200k context window. There is no cliff to warn about,
 * so the card reports and stops.
 *
 * The per-part figures still earn their place. "Design tokens is a third of
 * this" is a decision an owner can act on; "you are at 71% of a limit we made
 * up" is not.
 */
import { __, sprintf } from '@wordpress/i18n';
import InfoPopover from '../shared/InfoPopover';
import { TOKEN_EXPLANATION } from './copy';

/**
 * Render the cost card.
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.budget  { total, instruction }.
 * @param {Array}   props.costs   Per-part cost chips.
 * @param {boolean} props.enabled Whether context is being sent at all.
 * @return {Element} The card.
 */
export default function BudgetCard( { budget, costs, enabled } ) {
	return (
		<section className="albert-card albert-context__budget">
			<div className="albert-card__body">
				<div className="albert-context__budget-head">
					<span className="albert-context__budget-title">
						{ __( 'What this costs', 'albert-ai-butler' ) }
						<InfoPopover
							label={ __(
								'What is a token?',
								'albert-ai-butler'
							) }
						>
							<p>{ TOKEN_EXPLANATION }</p>
						</InfoPopover>
					</span>
					<span className="albert-context__budget-total">
						{ enabled
							? sprintf(
									/* translators: %d: estimated tokens the whole context costs. */
									__( '≈%d tokens', 'albert-ai-butler' ),
									budget.total
							  )
							: __( 'nothing', 'albert-ai-butler' ) }
					</span>
				</div>

				<p className="albert-context__budget-note">
					{ enabled
						? __(
								'An assistant reads this once, at the start of a conversation. Nothing breaks if it grows. Switch off anything an assistant does not need here and it stops being sent.',
								'albert-ai-butler'
						  )
						: __(
								'Nothing is being sent while context is switched off.',
								'albert-ai-butler'
						  ) }
				</p>

				{ enabled && costs.length > 0 && (
					<ul className="albert-context__chips">
						{ costs.map( ( chip ) => (
							<li key={ chip.key }>
								<span className="albert-badge albert-badge--outline">
									{ chip.label }
									<span className="albert-context__chip-count">
										{ chip.tokens }
									</span>
								</span>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</section>
	);
}
