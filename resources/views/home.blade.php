@extends('dashboard.overview-1')

@section('title', 'Inicio - ERP SEGURTRACK')
@section('header', 'Analítica')

@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li class="text-slate-600 cursor-text">Inicio</li>
        </ol>
    </nav>
@endsection

@section('content')
	@php
		$performanceCards = [
			[
				'tone' => 'success',
				'icon' => 'laptop',
				'title' => 'Top 5 categorías',
				'subtitle' => 'Categorías populares',
				'action' => 'Explorar categorías',
				
			],
			[
				'tone' => 'primary',
				'icon' => 'database',
				'title' => 'Destacados de marketing',
				'subtitle' => 'Campañas recientes',
				'action' => 'Explorar campañas',
			],
			[
				'tone' => 'primary',
				'icon' => 'inbox',
				'title' => 'Alertas de stock bajo',
				'subtitle' => 'Artículos por agotarse',
				'action' => 'Ver inventario',
			],
			[
				'tone' => 'success',
				'icon' => 'fingerprint',
				'title' => 'Favoritos de clientes',
				'subtitle' => 'Cliente del mes',
				'action' => 'Explorar productos',
			],
			[
				'tone' => 'success',
				'icon' => 'zap',
				'title' => 'Top 10 productos',
				'subtitle' => 'Productos destacados',
				'action' => 'Explorar productos',
			],
		];

		$orders = [
			[
				'icon' => 'book',
				'order' => 'IVR/20240301/VIII/I/7373080837',
				'category' => 'Libros',
				'customer' => 'Angelina Jolie',
				'statusIcon' => 'package-x',
				'statusClass' => 'text-primary',
				'status' => 'Cancelado',
				'date' => 'Lun ene 2023',
			],
			[
				'icon' => 'book',
				'order' => 'IVR/20240301/III/IX/4693222905',
				'category' => 'Libros',
				'customer' => 'Tom Hanks',
				'statusIcon' => 'clock4',
				'statusClass' => 'text-primary',
				'status' => 'En proceso',
				'date' => 'Mar mar 2022',
			],
			[
				'icon' => 'laptop',
				'order' => 'IVR/20240301/V/IX/9988288519',
				'category' => 'Electrónica',
				'customer' => 'Johnny Depp',
				'statusIcon' => 'hourglass',
				'statusClass' => 'text-primary',
				'status' => 'Pago pendiente',
				'date' => 'Dom nov 2021',
			],
			[
				'icon' => 'shirt',
				'order' => 'IVR/20240301/III/X/9127492052',
				'category' => 'Ropa',
				'customer' => 'Tom Hanks',
				'statusIcon' => 'package-check',
				'statusClass' => 'text-success',
				'status' => 'Entregado',
				'date' => 'Dom ago 2020',
			],
			[
				'icon' => 'gamepad2',
				'order' => 'IVR/20240301/VI/II/6010617598',
				'category' => 'Juegos',
				'customer' => 'Angelina Jolie',
				'statusIcon' => 'package-x',
				'statusClass' => 'text-primary',
				'status' => 'Cancelado',
				'date' => 'Sáb dic 2020',
			],
		];
	@endphp

	<div class="grid grid-cols-12 gap-x-6 gap-y-10">
		<div class="col-span-12">
			<div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
				<div class="text-base font-medium">Reporte general</div>
				<div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto">
					<div class="relative">
						<i data-lucide="calendar-check2" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3]"></i>
						<select class="disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm py-2 px-3 pr-8 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 group-[.form-inline]:flex-1 rounded-[0.5rem] pl-9 sm:w-44">
							<option value="custom-date">Fecha</option>
							<option value="daily">Diario</option>
							<option value="weekly">Semanal</option>
							<option value="monthly">Mensual</option>
							<option value="yearly">Anual</option>
						</select>
					</div>
					<div class="relative">
						<i data-lucide="calendar" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3]"></i>
						<input type="text" class="datepicker disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 group-[.form-inline]:flex-1 rounded-[0.5rem] pl-9 sm:w-64">
					</div>
				</div>
			</div>

			<div class="mt-3.5 grid grid-cols-12 gap-5">
				<div class="box box--stacked col-span-12 p-1 md:col-span-6 2xl:col-span-3">
					<div class="-mx-1 h-[244px] overflow-hidden [&_.tns-nav]:bottom-auto [&_.tns-nav]:ml-5 [&_.tns-nav]:mt-5 [&_.tns-nav]:w-auto [&_.tns-nav_button.tns-nav-active]:w-5 [&_.tns-nav_button.tns-nav-active]:bg-white/70 [&_.tns-nav_button]:mx-0.5 [&_.tns-nav_button]:h-2 [&_.tns-nav_button]:w-2 [&_.tns-nav_button]:bg-white/40">
						<div data-config="fade" class="tiny-slider">
							<div class="px-1">
								<div class="relative flex h-full w-full flex-col overflow-hidden rounded-[0.5rem] bg-gradient-to-b from-theme-2/90 to-theme-1/[0.85] p-5">
									<i data-lucide="medal" class="absolute right-0 top-0 -mr-5 -mt-5 h-36 w-36 rotate-[-10deg] transform fill-white/[0.03] stroke-[0.3] text-white/20"></i>
									<div class="mb-9 mt-12">
										<div class="text-2xl font-medium leading-snug text-white">¡Nueva función<br>desbloqueada!</div>
										<div class="mt-1.5 text-lg text-white/70">¡Mejora tu rendimiento!</div>
									</div>
									<a class="flex items-center font-medium text-white" href="#">
										Actualizar ahora
										<i data-lucide="move-right" class="stroke-[1] ml-1.5 h-4 w-4"></i>
									</a>
								</div>
							</div>
							<div class="px-1">
								<div class="relative flex h-full w-full flex-col overflow-hidden rounded-[0.5rem] bg-gradient-to-b from-theme-2/90 to-theme-1/90 p-5">
									<i data-lucide="database" class="absolute right-0 top-0 -mr-5 -mt-5 h-36 w-36 rotate-[-10deg] transform fill-white/[0.03] stroke-[0.3] text-white/20"></i>
									<div class="mb-9 mt-12">
										<div class="text-2xl font-medium leading-snug text-white">Mantente al día<br>con actualizaciones</div>
										<div class="mt-1.5 text-lg text-white/70">¡Nuevas funciones y mejoras!</div>
									</div>
									<a class="flex items-center font-medium text-white" href="#">
										Descubrir ahora
										<i data-lucide="arrow-right" class="stroke-[1] ml-1.5 h-4 w-4"></i>
									</a>
								</div>
							</div>
							<div class="px-1">
								<div class="relative flex h-full w-full flex-col overflow-hidden rounded-[0.5rem] bg-gradient-to-b from-theme-2/90 to-theme-1/90 p-5">
									<i data-lucide="gauge" class="absolute right-0 top-0 -mr-5 -mt-5 h-36 w-36 rotate-[-10deg] transform fill-white/[0.03] stroke-[0.3] text-white/20"></i>
									<div class="mb-9 mt-12">
										<div class="text-2xl font-medium leading-snug text-white">Potencia<br>tu flujo de trabajo</div>
										<div class="mt-1.5 text-lg text-white/70">¡Mejora el rendimiento!</div>
									</div>
									<a class="flex items-center font-medium text-white" href="#">
										Empezar
										<i data-lucide="arrow-right" class="stroke-[1] ml-1.5 h-4 w-4"></i>
									</a>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="box box--stacked col-span-12 flex flex-col p-5 md:col-span-6 2xl:col-span-3">
					<div class="flex items-center">
						<div class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
							<i data-lucide="database" class="stroke-[1] h-6 w-6 fill-primary/10 text-primary"></i>
						</div>
						<div class="ml-4">
							<div class="text-base font-medium">41k productos vendidos</div>
							<div class="mt-0.5 text-slate-500">En 21 tiendas</div>
						</div>
					</div>
					<div class="relative mb-6 mt-5 overflow-hidden">
						<div class="absolute inset-0 my-auto h-px whitespace-nowrap text-xs leading-[0] tracking-widest text-slate-400/60">.......................................................................</div>
						<div class="w-auto h-[100px]">
							<canvas data-index="2" data-border-color="primary" data-background-color="primary/0.3" class="chart report-line-chart relative z-10 -ml-1.5"></canvas>
						</div>
					</div>
					<div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-3">
						<div class="flex items-center">
							<div class="h-2 w-2 rounded-full bg-primary/70"></div>
							<div class="ml-2.5">Productos vendidos</div>
						</div>
						<div class="flex items-center">
							<div class="h-2 w-2 rounded-full bg-slate-400"></div>
							<div class="ml-2.5">Ubicaciones</div>
						</div>
					</div>
				</div>

				<div class="box box--stacked col-span-12 flex flex-col p-5 md:col-span-6 2xl:col-span-3">
					<div class="flex items-center">
						<div class="flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10">
							<i data-lucide="files" class="stroke-[1] h-6 w-6 fill-success/10 text-success"></i>
						</div>
						<div class="ml-4">
							<div class="text-base font-medium">36k productos enviados</div>
							<div class="mt-0.5 text-slate-500">En 14 almacenes</div>
						</div>
					</div>
					<div class="relative mb-6 mt-5 overflow-hidden">
						<div class="absolute inset-0 my-auto h-px whitespace-nowrap text-xs leading-[0] tracking-widest text-slate-400/60">.......................................................................</div>
						<div class="w-auto h-[100px]">
							<canvas data-index="0" data-border-color="success" data-background-color="success/0.3" class="chart report-line-chart relative z-10 -ml-1.5"></canvas>
						</div>
					</div>
					<div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-3">
						<div class="flex items-center">
							<div class="h-2 w-2 rounded-full bg-success/70"></div>
							<div class="ml-2.5">Total enviado</div>
						</div>
						<div class="flex items-center">
							<div class="h-2 w-2 rounded-full bg-slate-400"></div>
							<div class="ml-2.5">Almacenes</div>
						</div>
					</div>
				</div>

				<div class="box box--stacked col-span-12 flex flex-col p-5 md:col-span-6 2xl:col-span-3">
					<div class="flex items-center">
						<div class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
							<i data-lucide="zap" class="stroke-[1] h-6 w-6 fill-primary/10 text-primary"></i>
						</div>
						<div class="ml-4">
							<div class="text-base font-medium">3.7k órdenes procesadas</div>
							<div class="mt-0.5 text-slate-500">En 9 regiones</div>
						</div>
					</div>
					<div class="relative mb-6 mt-5">
						<div class="w-auto h-[100px]">
							<canvas class="chart report-donut-chart-3 relative z-10"></canvas>
						</div>
					</div>
					<div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-3">
						<div class="flex items-center">
							<div class="h-2 w-2 rounded-full bg-danger/70"></div>
							<div class="ml-2.5">Volumen de órdenes</div>
						</div>
						<div class="flex items-center">
							<div class="h-2 w-2 rounded-full bg-slate-400"></div>
							<div class="ml-2.5">Cobertura</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="col-span-12">
			<div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
				<div class="text-base font-medium">Insights de rendimiento</div>
				<div class="flex gap-x-3 gap-y-2 md:ml-auto">
					<button data-carousel="important-notes" data-target="prev" class="tiny-slider-navigator transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 font-medium rounded-[0.5rem] bg-white text-slate-600">
						<span class="flex h-5 w-3.5 items-center justify-center">
							<i data-lucide="chevron-left" class="stroke-[1] h-4 w-4"></i>
						</span>
					</button>
					<button data-carousel="important-notes" data-target="next" class="tiny-slider-navigator transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 font-medium rounded-[0.5rem] bg-white text-slate-600">
						<span class="flex h-5 w-3.5 items-center justify-center">
							<i data-lucide="chevron-right" class="stroke-[1] h-4 w-4"></i>
						</span>
					</button>
				</div>
			</div>

			<div class="-mx-2.5 mt-3.5">
				<div id="important-notes" data-config="performance-insight-slider-config" class="tiny-slider">
					@foreach($performanceCards as $card)
						<div class="px-2.5 pb-3">
							<div class="box box--stacked relative p-5">
								<div class="flex items-center">
									<div class="group flex items-center justify-center w-10 h-10 border rounded-full [&.primary]:border-primary/10 [&.primary]:bg-primary/10 [&.success]:border-success/10 [&.success]:bg-success/10 {{ $card['tone'] }}">
										<i data-lucide="{{ $card['icon'] }}" class="stroke-[1] w-5 h-5 group-[.primary]:text-primary group-[.primary]:fill-primary/10 group-[.success]:text-success group-[.success]:fill-success/10"></i>
									</div>
									<div class="ml-auto flex">
										
									</div>
								</div>

								<div class="mt-11">
									<div class="text-base font-medium">{{ $card['title'] }}</div>
									<div class="mt-0.5 text-slate-500">{{ $card['subtitle'] }}</div>
								</div>

								<a class="mt-4 flex items-center border-t border-dashed pt-4 font-medium text-primary" href="#">
									{{ $card['action'] }}
									<i data-lucide="arrow-right" class="stroke-[1] ml-1.5 h-4 w-4"></i>
								</a>
							</div>
						</div>
					@endforeach
				</div>
			</div>
		</div>

		<div class="col-span-12">
			<div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
				<div class="text-base font-medium">Órdenes recientes</div>
				<div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto">
					<div class="relative">
						<i data-lucide="calendar-check2" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3]"></i>
						<select class="disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm py-2 px-3 pr-8 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 group-[.form-inline]:flex-1 rounded-[0.5rem] pl-9 sm:w-44">
							<option value="custom-date">Fecha </option>
							<option value="daily">Diario</option>
							<option value="weekly">Semanal</option>
							<option value="monthly">Mensual</option>
							<option value="yearly">Anual</option>
						</select>
					</div>
					<div class="relative">
						<i data-lucide="calendar" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3]"></i>
						<input type="text" class="datepicker disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 group-[.form-inline]:flex-1 rounded-[0.5rem] pl-9 sm:w-64">
					</div>
				</div>
			</div>

			<div class="mt-2 overflow-auto lg:overflow-visible">
				<table class="w-full text-left border-separate border-spacing-y-[10px]">
					<tbody>
						@foreach($orders as $order)
							<tr>
								<td class="px-5 py-3 border-b box rounded-l-none rounded-r-none border-x-0 shadow-[5px_3px_5px_#00000005] first:rounded-l-[0.6rem] first:border-l last:rounded-r-[0.6rem] last:border-r">
									<div class="flex items-center">
										<i data-lucide="{{ $order['icon'] }}" class="h-6 w-6 fill-primary/10 stroke-[0.8] text-theme-1"></i>
										<div class="ml-3.5">
											<a class="whitespace-nowrap font-medium" href="#">{{ $order['order'] }}</a>
											<div class="mt-1 whitespace-nowrap text-xs text-slate-500">{{ $order['category'] }}</div>
										</div>
									</div>
								</td>

								<td class="px-5 py-3 border-b box w-60 rounded-l-none rounded-r-none border-x-0 shadow-[5px_3px_5px_#00000005] first:rounded-l-[0.6rem] first:border-l last:rounded-r-[0.6rem] last:border-r">
									<div class="mb-1 whitespace-nowrap text-xs text-slate-500">Cliente</div>
									<a class="flex items-center text-primary" href="#">
										<i data-lucide="external-link" class="h-3.5 w-3.5 stroke-[1.7]"></i>
										<div class="ml-1.5 whitespace-nowrap">{{ $order['customer'] }}</div>
									</a>
								</td>

								<td class="px-5 py-3 border-b box w-44 rounded-l-none rounded-r-none border-x-0 shadow-[5px_3px_5px_#00000005] first:rounded-l-[0.6rem] first:border-l last:rounded-r-[0.6rem] last:border-r">
									<div class="mb-1.5 whitespace-nowrap text-xs text-slate-500">Ítems</div>
									<div class="mb-1 flex">
									</div>
								</td>

								<td class="px-5 py-3 border-b box w-44 rounded-l-none rounded-r-none border-x-0 shadow-[5px_3px_5px_#00000005] first:rounded-l-[0.6rem] first:border-l last:rounded-r-[0.6rem] last:border-r">
									<div class="mb-1 whitespace-nowrap text-xs text-slate-500">Estado</div>
									<div class="flex items-center {{ $order['statusClass'] }}">
										<i data-lucide="{{ $order['statusIcon'] }}" class="h-3.5 w-3.5 stroke-[1.7]"></i>
										<div class="ml-1.5 whitespace-nowrap">{{ $order['status'] }}</div>
									</div>
								</td>

								<td class="px-5 py-3 border-b box w-44 rounded-l-none rounded-r-none border-x-0 shadow-[5px_3px_5px_#00000005] first:rounded-l-[0.6rem] first:border-l last:rounded-r-[0.6rem] last:border-r">
									<div class="mb-1 whitespace-nowrap text-xs text-slate-500">Fecha</div>
									<div class="whitespace-nowrap">{{ $order['date'] }}</div>
								</td>

								<td class="px-5 border-b box relative w-20 rounded-l-none rounded-r-none border-x-0 py-0 shadow-[5px_3px_5px_#00000005] first:rounded-l-[0.6rem] first:border-l last:rounded-r-[0.6rem] last:border-r">
									<div class="flex items-center justify-center">
										<div class="dropdown relative h-5">
											<button data-tw-toggle="dropdown" aria-expanded="false" class="cursor-pointer h-5 w-5 text-slate-500">
												<i data-lucide="more-vertical" class="stroke-[1] w-5 h-5 fill-slate-400/70 stroke-slate-400/70"></i>
											</button>
											<div class="dropdown-menu absolute z-[9999] hidden">
												<div class="dropdown-content rounded-md border-transparent bg-white p-2 shadow-[0px_3px_10px_#00000017] w-40">
													<a class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dropdown-item">
														<i data-lucide="wallet-cards" class="stroke-[1] mr-2 h-4 w-4"></i>
														Ver detalles
													</a>
													<a class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dropdown-item">
														<i data-lucide="file-signature" class="stroke-[1] mr-2 h-4 w-4"></i>
														Editar orden
													</a>
													<a class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dropdown-item">
														<i data-lucide="printer" class="stroke-[1] mr-2 h-4 w-4"></i>
														Imprimir factura
													</a>
												</div>
											</div>
										</div>
									</div>
								</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>

			<div class="flex-reverse mt-3 flex flex-col-reverse flex-wrap items-center gap-y-2 sm:flex-row">
				<nav class="mr-auto w-full flex-1 sm:w-auto">
					<ul class="flex w-full mr-0 sm:mr-auto sm:w-auto">
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3"><i data-lucide="chevrons-left" class="stroke-[1] h-4 w-4"></i></a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3"><i data-lucide="chevron-left" class="stroke-[1] h-4 w-4"></i></a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3">...</a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3">1</a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3 !box">2</a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3">3</a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3">...</a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3"><i data-lucide="chevron-right" class="stroke-[1] h-4 w-4"></i></a>
						</li>
						<li class="flex-1 sm:flex-initial">
							<a class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3"><i data-lucide="chevrons-right" class="stroke-[1] h-4 w-4"></i></a>
						</li>
					</ul>
				</nav>

				<select class="disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm py-2 px-3 pr-8 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 group-[.form-inline]:flex-1 rounded-[0.5rem] sm:w-20">
					<option>10</option>
					<option>25</option>
					<option>35</option>
					<option>50</option>
				</select>
			</div>
		</div>
	</div>
@endsection
