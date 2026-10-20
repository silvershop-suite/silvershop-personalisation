<%-- Reusable personalisation summary for one order line. Render inside an OrderItem scope
     (cart, side-cart, checkout summary, order confirmation, account order history, invoice).
     No-op for a non-personalised line. --%>
<% if $IsPersonalised %>
    <% require css('silvershop-suite/personalisation: css/personalisation.css') %>
    <dl class="personalisation-summary">
        <% loop $PersonalisationSummary %>
            <div class="personalisation-summary__row">
                <dt class="personalisation-summary__label">$Label</dt>
                <dd class="personalisation-summary__value">$Value</dd>
            </div>
        <% end_loop %>
    </dl>
<% end_if %>
