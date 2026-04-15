<script src="https://cdn.moyasar.com/mpf/1.15.0/moyasar.js"></script>

<div class="mysr-form"></div>

<script>
Moyasar.init({
    element: '.mysr-form',

    amount: {{ $total * 100 }}, // 🔥 من الكنترولر
    currency: 'SAR',
    description: 'حجز وحدة رقم {{ $unit->id }}',

    publishable_api_key: 'pk_test_xxxxx',

    callback_url: "{{ route('payment.success', ['unit' => $unit->id, 'checkin'=>$checkin, 'checkout'=>$checkout, 'total'=>$total]) }}",

    methods: ['creditcard','applepay']
});
</script>