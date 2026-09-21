/**
 * Shared SVG icons for {@link LayoutPicker}.
 *
 * Default artwork for common layout slugs (`grid`, `row`, `masonry`, …).
 * Modules may pass an `icons` override to LayoutPicker for network-specific
 * variants (e.g. X `timeline`); omit the prop to use this catalog.
 *
 * @package Easy_Social_Feed
 */

const GridIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<rect x="4" y="4" width="6" height="6" rx="1" />
		<rect x="14" y="4" width="6" height="6" rx="1" />
		<rect x="4" y="14" width="6" height="6" rx="1" />
		<rect x="14" y="14" width="6" height="6" rx="1" />
	</svg>
);

const RowIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<rect x="1.5" y="9" width="5" height="5" rx="0.75" />
		<rect x="7" y="9" width="5" height="5" rx="0.75" />
		<rect x="12.5" y="9" width="5" height="5" rx="0.75" />
		<rect x="18" y="9" width="4" height="5" rx="0.75" />
	</svg>
);

const HalfWidthIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<rect x="3" y="5" width="8" height="14" rx="1.5" />
		<rect x="13" y="5" width="8" height="5" rx="1" />
		<path d="M14 13h6M14 16h4" />
	</svg>
);

const FullWidthIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<rect x="4" y="4" width="16" height="8" rx="1.5" />
		<rect x="4" y="14" width="16" height="3" rx="0.5" />
		<path d="M5 19h10" />
	</svg>
);

const MasonryIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<rect x="3" y="4" width="8" height="7" rx="1" />
		<rect x="13" y="4" width="8" height="12" rx="1" />
		<rect x="3" y="13" width="8" height="7" rx="1" />
		<rect x="13" y="18" width="8" height="2" rx="0.5" />
	</svg>
);

const CarouselIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<path
			d="M3.5 9 L1.8 12 L3.5 15"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<path
			d="M20.5 9 L22.2 12 L20.5 15"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
		<rect x="4.5" y="7" width="5" height="10" rx="1" />
		<rect x="10.25" y="7" width="5" height="10" rx="1" />
		<rect x="16" y="7" width="4.5" height="10" rx="1" />
	</svg>
);

const TimelineIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		<rect x="4" y="4" width="16" height="3.5" rx="1" />
		<rect x="4" y="10.25" width="16" height="3.5" rx="1" />
		<rect x="4" y="16.5" width="16" height="3.5" rx="1" />
	</svg>
);

const ThumbnailIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		aria-hidden
	>
		{/* Narrow media column (~⅓) — distinct from Half Width’s 50/50 split. */}
		<rect x="3" y="5" width="5" height="14" rx="1.5" />
		<rect x="10" y="5" width="11" height="4" rx="1" />
		<path d="M11 12h9M11 15h7M11 18h5" />
	</svg>
);

const LAYOUT_ICONS = {
	grid: <GridIcon />,
	row: <RowIcon />,
	thumbnail: <ThumbnailIcon />,
	full_width: <FullWidthIcon />,
	masonry: <MasonryIcon />,
	carousel: <CarouselIcon />,
	half_width: <HalfWidthIcon />,
	timeline: <TimelineIcon />,
};

export default LAYOUT_ICONS;
