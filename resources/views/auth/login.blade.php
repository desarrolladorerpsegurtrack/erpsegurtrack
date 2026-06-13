@extends('layouts.layout')

@section('title', 'ERP SEGURTRACK')

@section('content')
    <div class="container grid grid-cols-12 px-5 py-10 sm:px-10 sm:py-14 md:px-36 lg:h-screen lg:max-w-[1550px] lg:py-0 lg:pl-14 lg:pr-12 xl:px-24 2xl:max-w-[1750px] overflow-hidden">     
        <div class="relative z-50 h-full col-span-12 p-7 sm:p-14 bg-white rounded-2xl lg:bg-transparent lg:pr-10 lg:col-span-5 xl:pr-24 2xl:col-span-4 lg:p-0">
            <div class="relative z-10 flex flex-col justify-center w-full h-full py-2 lg:py-8 xl:py-4 2xl:py-2">
                <div class="flex items-center justify-center">
                    <img src="{{ asset('images/logo-main.png') }}" alt="SEGURTRACK" class="h-auto w-auto" />
                </div>
                <div class="mt-10">
                    <div class="mt-6">
                        <form method="POST" action="{{ route('login.attempt') }}">
                            @csrf
                            @error('usuario')
                                <div class="mb-4 rounded-md border px-4 py-3 text-base font-semibold" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                                    {{ $message }}
                                </div>
                            @enderror
                            <div class="mt-3 text-center xl:mt-5 xl:text-left">
                                <input id="usuario-input" data-tw-merge="" type="text" name="usuario" value="{{ old('usuario') }}" autocomplete="username" required autofocus placeholder="Usuario" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm shadow-sm placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 [&[type='file']]:border file:mr-4 file:py-2 file:px-4 file:rounded-l-md file:border-0 file:border-r-[1px] file:border-slate-100/10 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-500/70 hover:file:bg-200 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10 block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5">
                            </div>
                            <div class="mt-3 text-center xl:mt-5 xl:text-left">
                                <div class="relative">
                                    <input data-tw-merge="" id="password-input" type="password" name="password" minlength="8" required autocomplete="current-password" placeholder="Contraseña" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm shadow-sm placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 [&[type='file']]:border file:mr-4 file:py-2 file:px-4 file:rounded-l-md file:border-0 file:border-r-[1px] file:border-slate-100/10 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-500/70 hover:file:bg-200 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10 block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5 pr-11">
                                    <button type="button" id="quick-credencial-toggle-password" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-slate-600" aria-label="Mostrar contraseña">
                                        <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden">
                                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.52 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/>
                                        </svg>
                                    </button>
                                </div>
                                @error('password')
                                    <span id="password-error" class="mt-2 block text-xs text-red-600 hidden">La contraseña debe tener al menos 8 caracteres.</span>
                                @enderror
                            </div>
                            <div class="mt-5 text-center xl:mt-8 xl:text-left">
                                <button id="submit-btn" data-tw-merge="" type="submit" class="border shadow-sm inline-flex items-center justify-center gap-2 px-3 font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed bg-primary border-primary text-white dark:border-primary rounded-full w-full py-3.5 xl:mr-3 hover:bg-primary/90">
                                    Iniciar Sesion
                                    <i data-lucide="log-in" class="h-4 w-4"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
   <div class="container fixed inset-0 grid h-screen w-screen grid-cols-12 pl-14 pr-12 lg:max-w-[1550px] xl:px-24 2xl:max-w-[1750px] mx-auto">
        <div id="panel-blanco" class="relative h-screen col-span-12 lg:col-span-5 2xl:col-span-4 z-20 after:bg-white after:hidden after:lg:block after:content-[''] after:absolute after:right-0 after:inset-y-0 after:bg-gradient-to-b after:from-white after:to-slate-100/80 after:w-[800%] after:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0] before:content-[''] before:hidden before:lg:block before:absolute before:right-0 before:inset-y-0 before:my-6 before:bg-gradient-to-b before:from-white/10 before:to-slate-50/10 before:bg-white/50 before:w-[800%] before:-mr-4 before:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0]"></div>
        
        <div id="contenedor-foto-js" class="h-full col-span-7 2xl:col-span-8 lg:relative pointer-events-none">
            <div id="foto-bg" class="absolute inset-0 bg-cover bg-center bg-no-repeat w-full h-full" 
                 style="background-image: url('{{ asset('images/fondo_login.png') }}');">
            </div>
        </div>
    </div>
    <script>
        const userInp = document.getElementById('usuario-input');
        const passInp = document.getElementById('password-input');
        const submitBtn = document.getElementById('submit-btn');
        const passError = document.getElementById('password-error');

        function validarFormulario() {
            const isUserValid = userInp.value.length >= 2;
            const isPassValid = passInp.value.length >= 8;
            
            if(passError) {
                if (passInp.value.length > 0 && passInp.value.length < 8) {
                    passError.classList.remove('hidden');
                } else {
                    passError.classList.add('hidden');
                }
            }
            
            // Habilitar o deshabilitar botón
            if (isUserValid && isPassValid) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            }
        }

        userInp.addEventListener('input', validarFormulario);
        passInp.addEventListener('input', validarFormulario);

        // Mostrar/ocultar botón y alternar iconos ojo/oculto en login
        (function () {
            const input = document.getElementById('password-input');
            const toggleBtn = document.getElementById('quick-credencial-toggle-password');
            if (!input || !toggleBtn) return;

            const iconEye = toggleBtn.querySelector('#icon-eye');
            const iconEyeOff = toggleBtn.querySelector('#icon-eye-off');

            const updateToggleVisibility = () => {
                const hasText = String(input.value || '').trim().length > 0;
                toggleBtn.style.display = hasText ? 'flex' : 'none';
            };

            const setIconState = (passwordVisible) => {
                if (iconEye && iconEyeOff) {
                    iconEye.classList.toggle('hidden', !passwordVisible);
                    iconEyeOff.classList.toggle('hidden', passwordVisible);
                    return;
                }

                // Fallback: cambiar innerHTML si no se encontraron los SVG internos
                toggleBtn.innerHTML = passwordVisible
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10 10 0 0 1 6.06 6.06"/><path d="M1 1l22 22"/></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
            };

            // Estado inicial
            updateToggleVisibility();
            setIconState(false);

            input.addEventListener('input', updateToggleVisibility);
            input.addEventListener('focus', updateToggleVisibility);
            input.addEventListener('blur', updateToggleVisibility);

            toggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const willShow = input.type === 'password';
                input.type = willShow ? 'text' : 'password';
                setIconState(willShow);
                input.focus();
            });
        })();
       // Código JS definitivo para forzar la imagen a pantalla completa en el lado derecho
        function ajustarFondoImagen() {
            const panelBlanco = document.getElementById('panel-blanco');
            const contenedorFoto = document.getElementById('contenedor-foto-js');

            if (!panelBlanco || !contenedorFoto) return;

            if (window.innerWidth >= 1024) {
                // Medimos el borde derecho real del panel blanco
                const rectPanel = panelBlanco.getBoundingClientRect();

                // Solapamiento para que la imagen quede metida por debajo del panel blanco
                const baseOverlap = 120; // px - valor por defecto
                const largeOverlap = window.innerWidth >= 1600 ? 480 : baseOverlap;
                const leftPx = Math.max(0, Math.floor(rectPanel.right - largeOverlap));

                // Forzamos el DIV interno a posición fixed para evitar que algún ancestro con transform o estilos
                const fotoBg = document.getElementById('foto-bg');
                if (fotoBg) {
                    fotoBg.style.position = 'fixed';
                    fotoBg.style.top = '0';
                    fotoBg.style.bottom = '0';
                    fotoBg.style.left = `${leftPx}px`;
                    fotoBg.style.right = '0';
                    fotoBg.style.height = '100vh';
                    fotoBg.style.backgroundSize = 'cover';
                    fotoBg.style.backgroundPosition = 'center';
                    fotoBg.style.zIndex = '-1';
                }
            } else {
                // En móviles forzamos que el fondo cubra todo el viewport real
                const fotoBg = document.getElementById('foto-bg');
                if (fotoBg) {
                    fotoBg.style.position = 'fixed';
                    fotoBg.style.top = '0';
                    fotoBg.style.left = '0';
                    fotoBg.style.right = '0';
                    fotoBg.style.height = `${window.innerHeight}px`;
                    fotoBg.style.width = '100%';
                    fotoBg.style.backgroundSize = 'cover';
                    fotoBg.style.backgroundPosition = 'center';
                    fotoBg.style.zIndex = '-1';
                }

                // Aseguramos que el contenedor padre no limite la altura del fondo
                contenedorFoto.style.position = '';
                contenedorFoto.style.top = '';
                contenedorFoto.style.bottom = '';
                contenedorFoto.style.right = '';
                contenedorFoto.style.left = '';
                contenedorFoto.style.width = '';
                contenedorFoto.style.height = '';
                contenedorFoto.style.pointerEvents = '';
            }
        }

        // Asegurar que se ejecute en todos los ciclos de carga del navegador
        window.addEventListener('DOMContentLoaded', ajustarFondoImagen);
        window.addEventListener('load', ajustarFondoImagen);
        window.addEventListener('resize', ajustarFondoImagen);
        window.addEventListener('orientationchange', ajustarFondoImagen);
        ajustarFondoImagen();
    </script>
@endsection
