const { __, sprintf } = wp.i18n;
document.addEventListener('DOMContentLoaded', function () {
    const convertBtn = document.getElementById('webp-start');
    const barInner = document.getElementById('webp-progress-inner');
    const progressWrapper = document.getElementById('webp-progress-wrapper');
    const status = document.getElementById('webp-status');
    fetch(webpAutogen.ajaxUrl + '?action=webp_autogen_get_converted_count')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const converted = data.data.converted;
                const total = data.data.total;
                if (total > 0) {
                    status.innerHTML = `<p><strong>Converted:</strong> <span style="color: green;">${converted} / ${total}</span> | <strong>Remaining:</strong> ${total - converted}</p>`;
                } else {
                    status.innerHTML = `<p>${__('No images found to convert.', 'webp-autogen')}</p>`;
                }
            } else {
                status.innerHTML = `<p>${__('Unable to retrieve conversion status.', 'webp-autogen')}</p>`;
            }
        })
        .catch(error => {
            status.innerHTML = `<p>${__('Error fetching conversion status.', 'webp-autogen')}</p>`;
        });
    let lastProgress = 0;

    function runConversion() {
        convertBtn.disabled = true;
        convertBtn.innerHTML = 'Conversion in Progress <span class="spinnera"></span>';
        progressWrapper.style.display = 'block';
        barInner.style.width = '0%';
        barInner.textContent = '0%';
        fetch(webpAutogen.ajaxUrl + '?action=webp_autogen_convert_batch')
            .then(response => {
                if (!response.ok) {
                    throw new Error(__('Network response was not ok', 'webp-autogen'));
                }
                return response.json();
            })
            .then(data => {
                if (data.total > 0) {
                    let percent = Math.round((data.converted / data.total) * 100);
                    if (percent >= lastProgress + 5) {
                        lastProgress = percent;
                        barInner.style.width = percent + '%';
                        barInner.textContent = percent + '%';
                    }
                    status.innerHTML = `<p><strong>Converted:</strong> <span style="color: #77940f;">${data.converted} / ${data.total} </span>| Remaining: <strong>${data.remaining}</strong></p>`;
                    if (data.remaining > 0) {
                        setTimeout(runConversion, 1000);
                    } else {
                        barInner.style.width = '100%';
                        barInner.textContent = '100%';
                        alert(__('✅ WebP Conversion completed!', 'webp-autogen'));
                        convertBtn.disabled = false;
                        convertBtn.innerHTML = `<p>${__('Start WebP Conversion of all images already uploaded', 'webp-autogen')}</p>`;
                    }
                } else {
                    status.innerHTML = `<p>${__('No images found to convert.', 'webp-autogen')}</p>`;
                    convertBtn.disabled = false;
                    convertBtn.innerHTML = '';
                    convertBtn.innerHTML = `<p>${__('Start WebP Conversion of all images already uploaded', 'webp-autogen')}</p>`;
                }
            })
            .catch(error => {
                status.innerHTML = `<p>Error occurred during conversion:<span style="color: red;"> ${error.message}</span></p>`;
                convertBtn.disabled = false;
                convertBtn.innerHTML = `<p>${__('Start WebP Conversion of all images already uploaded', 'webp-autogen')}</p>`;
            });
    }
    convertBtn.addEventListener('click', runConversion);
});