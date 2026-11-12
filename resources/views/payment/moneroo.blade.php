<script>
    const urlParams = new URLSearchParams(window.location.search);

    const paymentId = urlParams.get('paymentId');
    const paymentStatus = urlParams.get('paymentStatus');
    
    if (window.opener) {

        window.opener.postMessage({
            status: 'success',
            paymentId: paymentId || 'your-payment-reference',
            paymentStatus: paymentStatus
        },  '*');
        window.close();
    } else {
         window.location.href = "https://jumli.africa/user-subscription";
    }
</script> 