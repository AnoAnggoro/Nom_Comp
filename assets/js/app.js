document.querySelectorAll('[data-role-switch]').forEach((switcher) => {
    const buttons = switcher.querySelectorAll('[data-role-value]');
    const input = switcher.parentElement.querySelector('[data-role-input]');
    const loginLabel = switcher.parentElement.querySelector('[data-auth-login-label]');
    const loginInput = switcher.parentElement.querySelector('[data-auth-login-id]');
    const roleLabel = switcher.parentElement.querySelector('[data-auth-role-label]');
    const roleCodeInput = switcher.parentElement.querySelector('[data-auth-role-code]');
    const forgotPasswordLink = switcher.parentElement.querySelector('[data-forgot-password-link]');

    const syncRoleUi = (role) => {
        if (loginLabel) {
            loginLabel.textContent = role === 'guru' ? 'NIP' : 'NIS';
        }

        if (loginInput) {
            const nextPlaceholder = role === 'guru'
                ? loginInput.dataset.placeholderGuru
                : loginInput.dataset.placeholderSiswa;
            if (nextPlaceholder) {
                loginInput.placeholder = nextPlaceholder;
            }
        }

        if (roleLabel) {
            roleLabel.textContent = role === 'guru' ? 'Kode Akses Guru' : 'Kode Akses Siswa';
        }

        if (roleCodeInput) {
            const nextPlaceholder = role === 'guru'
                ? roleCodeInput.dataset.roleCodePlaceholderGuru
                : roleCodeInput.dataset.roleCodePlaceholderSiswa;
            if (nextPlaceholder) {
                roleCodeInput.placeholder = nextPlaceholder;
            }
        }

        if (forgotPasswordLink) {
            forgotPasswordLink.href = `forgot-password.php?role=${encodeURIComponent(role)}`;
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            buttons.forEach((item) => item.classList.remove('is-active'));
            button.classList.add('is-active');

            if (input) {
                input.value = button.dataset.roleValue || 'siswa';
                syncRoleUi(input.value);
            }
        });
    });

    if (input) {
        syncRoleUi(input.value || 'siswa');
    }
});

document.querySelectorAll('[data-demo-login-id], [data-demo-email]').forEach((card) => {
    card.addEventListener('click', () => {
        const loginField = document.querySelector('[data-auth-login-id]') || document.querySelector('[data-auth-email]');
        const passwordField = document.querySelector('[data-auth-password]');
        const roleCodeField = document.querySelector('[data-auth-role-code]');
        const roleInput = document.querySelector('[data-role-input]');

        if (loginField) {
            loginField.value = card.dataset.demoLoginId || card.dataset.demoEmail || '';
        }

        if (passwordField) {
            passwordField.value = card.dataset.demoPassword || '';
        }

        if (roleCodeField) {
            roleCodeField.value = card.dataset.demoRoleCode || '';
        }

        if (roleInput && card.dataset.demoRole) {
            roleInput.value = card.dataset.demoRole;
            const activeButton = document.querySelector(`[data-role-value="${card.dataset.demoRole}"]`);
            if (activeButton) {
                const switcher = activeButton.closest('[data-role-switch]');
                if (switcher) {
                    switcher.querySelectorAll('[data-role-value]').forEach((button) => button.classList.remove('is-active'));
                    activeButton.classList.add('is-active');
                }
            }
        }
    });
});

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const field = button.parentElement?.querySelector('input');
        if (!field) {
            return;
        }

        field.type = field.type === 'password' ? 'text' : 'password';
        button.classList.toggle('is-visible', field.type === 'text');
        button.innerHTML = field.type === 'text'
            ? (button.dataset.eyeOff || '')
            : (button.dataset.eyeOn || '');
    });
});

document.querySelectorAll('[data-custom-select]').forEach((selectRoot) => {
    const trigger = selectRoot.querySelector('[data-custom-select-trigger]');
    const menu = selectRoot.querySelector('[data-custom-select-menu]');
    const valueInput = selectRoot.querySelector('[data-custom-select-value]');
    const label = selectRoot.querySelector('[data-custom-select-label]');
    const options = selectRoot.querySelectorAll('[data-custom-select-option]');

    if (!trigger || !menu || !valueInput || !label) {
        return;
    }

    const closeMenu = () => {
        selectRoot.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
    };

    const openMenu = () => {
        selectRoot.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
    };

    trigger.addEventListener('click', () => {
        if (selectRoot.classList.contains('is-open')) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    options.forEach((option) => {
        if (option.dataset.value === valueInput.value) {
            option.classList.add('is-active');
        }

        option.addEventListener('click', () => {
            const nextValue = option.dataset.value || '';
            const nextLabel = option.dataset.label || nextValue;

            valueInput.value = nextValue;
            label.textContent = nextLabel;

            options.forEach((item) => item.classList.toggle('is-active', item === option));
            closeMenu();
        });
    });

    document.addEventListener('click', (event) => {
        if (!selectRoot.contains(event.target)) {
            closeMenu();
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
});

const revealTargets = document.querySelectorAll('.feature-card, .case-card, .module-card, .panel-card, .knowledge-card, .summary-card');

if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    revealTargets.forEach((target) => observer.observe(target));
}

(() => {
    const guruPage = document.querySelector('.guru-page');
    const sidebar = guruPage?.querySelector('.guru-sidebar');
    const toggleButton = guruPage?.querySelector('[data-guru-sidebar-toggle]');
    const overlay = guruPage?.querySelector('[data-guru-sidebar-overlay]');

    if (!guruPage || !sidebar || !toggleButton || !overlay) {
        return;
    }

    const mobileMedia = window.matchMedia('(max-width: 1080px)');
    let ignoreOverlayClickUntil = 0;

    const closeSidebar = () => {
        guruPage.classList.remove('is-sidebar-open');
        toggleButton.setAttribute('aria-expanded', 'false');
        sidebar.style.transform = '';
        overlay.style.opacity = '';
        overlay.style.pointerEvents = '';
    };

    const openSidebar = () => {
        guruPage.classList.add('is-sidebar-open');
        toggleButton.setAttribute('aria-expanded', 'true');
        sidebar.style.transform = 'translateX(0)';
        overlay.style.opacity = '1';
        overlay.style.pointerEvents = 'auto';
        ignoreOverlayClickUntil = Date.now() + 260;
    };

    toggleButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (!mobileMedia.matches) {
            return;
        }

        if (guruPage.classList.contains('is-sidebar-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay.addEventListener('click', () => {
        if (Date.now() < ignoreOverlayClickUntil) {
            return;
        }
        closeSidebar();
    });

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileMedia.matches) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    const handleViewportChange = (event) => {
        if (!event.matches) {
            closeSidebar();
        }
    };

    if (typeof mobileMedia.addEventListener === 'function') {
        mobileMedia.addEventListener('change', handleViewportChange);
    } else {
        mobileMedia.addListener(handleViewportChange);
    }
})();

(() => {
    const featureArts = document.querySelectorAll('.feature-network-card .feature-art');
    if (!featureArts.length) {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    featureArts.forEach((art) => {
        const reset = () => {
            art.style.setProperty('--tilt-x', '0deg');
            art.style.setProperty('--tilt-y', '0deg');
        };

        art.addEventListener('pointermove', (event) => {
            const rect = art.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width;
            const y = (event.clientY - rect.top) / rect.height;

            const tiltY = (x - 0.5) * 10;
            const tiltX = (0.5 - y) * 8;

            art.style.setProperty('--tilt-x', `${tiltX.toFixed(2)}deg`);
            art.style.setProperty('--tilt-y', `${tiltY.toFixed(2)}deg`);
        });

        art.addEventListener('pointerleave', reset);
        art.addEventListener('pointercancel', reset);
    });
})();

(() => {
    const classPickerPage = document.querySelector('.class-picker-page');
    if (!classPickerPage) {
        return;
    }

    classPickerPage.querySelectorAll('[data-class-remove-confirm]').forEach((button) => {
        button.addEventListener('click', (event) => {
            const isConfirmed = window.confirm('Yakin ingin menghapus kelas ini dari akun Anda?');
            if (!isConfirmed) {
                event.preventDefault();
            }
        });
    });
})();

(() => {
    const quizRunner = document.querySelector('.siswa-quiz-runner-card');
    if (!quizRunner) {
        return;
    }

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });

    const form = quizRunner.querySelector('.siswa-quiz-form');
    const optionInputs = quizRunner.querySelectorAll('.siswa-quiz-option input[type="radio"]');
    const submitButton = quizRunner.querySelector('[data-quiz-submit]');
    const liveFeedback = quizRunner.querySelector('[data-quiz-live-feedback]');
    const timerCard = quizRunner.querySelector('[data-quiz-timer]');
    const timerLabel = quizRunner.querySelector('[data-quiz-timer-label]');
    const correctOption = (form?.dataset.correctOption || '').toLowerCase();
    const timeoutToken = form?.dataset.quizTimeoutToken || '__timeout__';
    const autoNextDelay = Number(form?.dataset.autoNextDelay || 2200);
    const defaultSubmitLabel = submitButton ? submitButton.textContent.trim() : 'Cek & Lanjut';
    const timerLimit = Number(timerCard?.dataset.quizTimeLimit || 0);
    let timerRemaining = Number(timerCard?.dataset.quizTimeRemaining || 0);
    let timerIntervalId = null;

    const clearTimer = () => {
        if (timerIntervalId !== null) {
            window.clearInterval(timerIntervalId);
            timerIntervalId = null;
        }
    };

    const formatSeconds = (seconds) => {
        const safeSeconds = Math.max(0, Number(seconds) || 0);
        const minutes = Math.floor(safeSeconds / 60);
        const remainingSeconds = safeSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    };

    const syncTimerUi = () => {
        if (!timerCard || !timerLabel || timerLimit <= 0) {
            return;
        }

        timerLabel.textContent = formatSeconds(timerRemaining);
        timerCard.classList.toggle('is-warning', timerRemaining > 0 && timerRemaining <= 10);
        timerCard.classList.toggle('is-critical', timerRemaining === 0);
    };

    const submitAsTimeout = () => {
        if (!form || form.dataset.revealState === 'pending') {
            return;
        }

        clearTimer();
        form.dataset.revealState = 'pending';

        const existingSelectedOptionField = form.querySelector('input[data-auto-selected-option="1"]');
        if (existingSelectedOptionField) {
            existingSelectedOptionField.remove();
        }

        const timeoutField = document.createElement('input');
        timeoutField.type = 'hidden';
        timeoutField.name = 'selected_option';
        timeoutField.value = timeoutToken;
        timeoutField.setAttribute('data-auto-selected-option', '1');
        form.appendChild(timeoutField);

        optionInputs.forEach((input) => {
            input.required = false;
            input.disabled = true;
        });

        quizRunner.querySelectorAll('.siswa-quiz-option').forEach((card) => {
            card.classList.remove('is-selected');
            card.classList.add('is-locked');
        });

        if (liveFeedback) {
            liveFeedback.classList.add('is-visible', 'is-wrong');
            liveFeedback.classList.remove('is-correct');
            liveFeedback.textContent = 'Waktu habis. Sistem lanjut ke soal berikutnya.';
        }

        if (submitButton) {
            submitButton.textContent = 'Waktu habis...';
            submitButton.disabled = true;
        }

        window.setTimeout(() => {
            form.submit();
        }, 350);
    };

    const startTimer = () => {
        if (!timerCard || timerLimit <= 0) {
            return;
        }

        timerRemaining = Math.max(0, timerRemaining);
        syncTimerUi();
        if (timerRemaining <= 0) {
            submitAsTimeout();
            return;
        }

        timerIntervalId = window.setInterval(() => {
            if (form?.dataset.revealState === 'pending') {
                clearTimer();
                return;
            }

            timerRemaining = Math.max(0, timerRemaining - 1);
            syncTimerUi();
            if (timerRemaining <= 0) {
                submitAsTimeout();
            }
        }, 1000);
    };

    const updateSubmitLabel = () => {
        if (!submitButton) {
            return;
        }

        const selected = quizRunner.querySelector('.siswa-quiz-option input[type="radio"]:checked');
        if (!selected) {
            submitButton.textContent = 'Pilih Jawaban Dulu';
            submitButton.disabled = true;
            return;
        }

        if (submitButton.textContent.trim() === 'Pilih Jawaban Dulu') {
            submitButton.textContent = defaultSubmitLabel;
        }
        submitButton.disabled = false;
    };

    optionInputs.forEach((input) => {
        const optionCard = input.closest('.siswa-quiz-option');
        if (!optionCard) {
            return;
        }

        input.addEventListener('change', () => {
            quizRunner.querySelectorAll('.siswa-quiz-option').forEach((card) => card.classList.remove('is-selected'));
            optionCard.classList.add('is-selected');
            updateSubmitLabel();
        });

        optionCard.addEventListener('pointerdown', () => {
            optionCard.classList.add('is-pressed');
        });

        optionCard.addEventListener('pointerup', () => {
            optionCard.classList.remove('is-pressed');
        });

        optionCard.addEventListener('pointerleave', () => {
            optionCard.classList.remove('is-pressed');
        });
    });

    if (form && submitButton) {
        form.addEventListener('submit', (event) => {
            if (form.dataset.revealState === 'pending') {
                event.preventDefault();
                return;
            }

            const selectedInput = quizRunner.querySelector('.siswa-quiz-option input[type="radio"]:checked');
            if (!selectedInput || !correctOption) {
                return;
            }

            event.preventDefault();
            clearTimer();
            form.dataset.revealState = 'pending';

            const selectedOption = (selectedInput.value || '').toLowerCase();
            const isCorrect = selectedOption === correctOption;

            // Radios are disabled during reveal, so mirror selected value in hidden field.
            const existingSelectedOptionField = form.querySelector('input[data-auto-selected-option="1"]');
            if (existingSelectedOptionField) {
                existingSelectedOptionField.remove();
            }
            const selectedOptionField = document.createElement('input');
            selectedOptionField.type = 'hidden';
            selectedOptionField.name = 'selected_option';
            selectedOptionField.value = selectedOption;
            selectedOptionField.setAttribute('data-auto-selected-option', '1');
            form.appendChild(selectedOptionField);

            quizRunner.querySelectorAll('.siswa-quiz-option').forEach((card) => {
                const input = card.querySelector('input[type="radio"]');
                if (!input) {
                    return;
                }

                const optionValue = (input.value || '').toLowerCase();
                card.classList.remove('is-selected');
                card.classList.add('is-locked');

                if (optionValue === correctOption) {
                    card.classList.add('is-reveal-correct');
                }

                if (optionValue === selectedOption && selectedOption !== correctOption) {
                    card.classList.add('is-reveal-wrong');
                }

                input.disabled = true;
            });

            if (liveFeedback) {
                liveFeedback.classList.add('is-visible');
                liveFeedback.classList.toggle('is-correct', isCorrect);
                liveFeedback.classList.toggle('is-wrong', !isCorrect);
                liveFeedback.textContent = isCorrect
                    ? 'Jawaban benar. Lanjut ke soal berikutnya...'
                    : 'Jawaban salah. Jawaban benar sudah ditandai hijau.';
            }

            submitButton.textContent = 'Lanjut otomatis...';
            submitButton.disabled = true;

            window.setTimeout(() => {
                form.submit();
            }, autoNextDelay);
        });
    }

    updateSubmitLabel();
    startTimer();
})();
