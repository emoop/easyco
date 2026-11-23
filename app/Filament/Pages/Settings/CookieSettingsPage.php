<?php

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AuthorizesViaStaffPermission;
use App\Filament\Design\InfoHint;
use App\Filament\NavigationGroup;
use App\Http\Controllers\Storefront\CookiePreviewController;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CookieSettings;
use App\Storefront\Header\AppearanceRecompiler;
use App\Storefront\Pages\PageReader;
use BackedEnum;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CC-1 (cookie-consent.md): «Поверителност → Бисквитки». `/admin/privacy-cookies`, PRIVACY_MANAGE: checked on open, on EVERY Livewire request (`boot()`) and again in the save. The settings are
 * CookieSettings' (the one reader/writer: closed lists, ranges, hex, plain texts, the contrast of the button colour, the text version). A save goes through the SAME refresh as the «Дизайн»
 * publish: the token stylesheet is recompiled (the colour), the appearance payload replaced (the texts) and every page purged (AppearanceRecompiler).
 *
 * The preview is the REAL banner partial in a frame (CookiePreviewController): static and inert, the draft values, never a cookie. The visible warning shows while no published policy page is picked.
 */
class CookieSettingsPage extends Page
{
    use AuthorizesViaStaffPermission;
    use ServerSideValidation;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** The signed url of the preview frame (set on open and when a draft value changes; never on a plain render). */
    public string $previewSrc = '';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $slug = 'privacy-cookies';

    public function getTitle(): string
    {
        return __('cookies_admin.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('cookies_admin.navigation_label');
    }

    /** The full page title as the browser tooltip (a long label is cut by the sidebar). */
    public static function getNavigationItems(): array
    {
        return array_map(
            static fn (\Filament\Navigation\NavigationItem $item): \Filament\Navigation\NavigationItem => $item->extraAttributes(['title' => __('cookies_admin.title')]),
            parent::getNavigationItems(),
        );
    }

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::PRIVACY;
    }

    /** After the forget log (10) and the privacy settings (20). */
    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    protected static function accessPermission(): ?Permission
    {
        return Permission::PRIVACY_MANAGE;
    }

    public static function canAccess(): bool
    {
        return static::staffCanForAction(static::accessPermission());
    }

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $current = app(CookieSettings::class)->current();
        $current['page'] = $this->publishedPageId($current['page']);
        $current['preview_layer'] = $this->data['preview_layer'] ?? 'summary';

        $this->form->fill($current);
        $this->refreshPreview();
    }

    /** A draft value changed: the preview frame gets a fresh signed url (the page's own controls never write a cookie). */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'data.')) {
            $this->refreshPreview();
        }
    }

    public function form(Schema $schema): Schema
    {
        $pages = app(PageReader::class)->publishedOptions();

        return $schema
            ->components([
                Toggle::make('enabled')
                    ->label(__('cookies_admin.fields.enabled'))
                    ->afterLabel(InfoHint::make(__('cookies_admin.hints.enabled'), 'cookies_enabled'))
                    ->live(),
                Select::make('position')
                    ->label(__('cookies_admin.fields.position'))
                    ->afterLabel(InfoHint::make(__('cookies_admin.hints.position'), 'cookies_position'))
                    ->options(array_combine(CookieSettings::POSITIONS, array_map(static fn (string $p): string => __('cookies_admin.positions.'.$p), CookieSettings::POSITIONS)))
                    ->required()->native(false)->live(),
                Select::make('view')
                    ->label(__('cookies_admin.fields.view'))
                    ->afterLabel(InfoHint::make(__('cookies_admin.hints.view'), 'cookies_view'))
                    ->options(array_combine(CookieSettings::VIEWS, array_map(static fn (string $v): string => __('cookies_admin.views.'.$v), CookieSettings::VIEWS)))
                    ->required()->native(false)->live(),
                TextInput::make('months')
                    ->label(__('cookies_admin.fields.months'))
                    ->afterLabel(InfoHint::make(__('cookies_admin.hints.months'), 'cookies_months'))
                    ->numeric()->integer()->minValue(CookieSettings::MIN_MONTHS)->maxValue(CookieSettings::MAX_MONTHS)->required(),
                Select::make('page')
                    ->label(__('cookies_admin.fields.page'))
                    ->afterLabel(InfoHint::make(__('cookies_admin.hints.page'), 'cookies_page'))
                    ->options($pages)->placeholder(__('cookies_admin.no_page'))->native(false)->live(),
                ColorPicker::make('color')
                    ->label(__('cookies_admin.fields.color'))
                    ->afterLabel(InfoHint::make(__('cookies_admin.hints.color'), 'cookies_color'))
                    ->hex()->required()->live(onBlur: true),
                Tabs::make('texts')
                    ->label(__('cookies_admin.fields.texts'))
                    ->tabs(array_map(fn (string $locale): Tab => $this->localeTab($locale), CookieSettings::LOCALES))
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    private function localeTab(string $locale): Tab
    {
        $fields = [];

        foreach (CookieSettings::TEXT_LIMITS as $key => $limit) {
            $long = in_array($key, ['text', 'necessary_text', 'ads_text'], true);
            $field = $long ? Textarea::make("texts.{$locale}.{$key}")->rows(3) : TextInput::make("texts.{$locale}.{$key}");

            $field->label(__('cookies_admin.text_fields.'.$key))->required()->maxLength($limit)->live(onBlur: true);
            $long && $field->columnSpanFull();
            $key === 'title' && $field->afterLabel(InfoHint::make(__('cookies_admin.hints.texts'), 'cookies_texts_'.$locale));

            $fields[] = $field;
        }

        return Tab::make($locale)->label(__('cookies_admin.locales.'.$locale))->schema($fields)->columns(2);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 5])->schema([
                Group::make([
                    View::make('filament.privacy.cookies-warning')->visible(fn (): bool => $this->policyWarning()),
                    View::make('filament.privacy.cookies-fallback-warning')->visible(fn (): bool => $this->fallbackWarning()),
                    $this->getFormContentComponent(),
                ])->columnSpan(['default' => 1, 'xl' => 3]),
                Group::make([
                    View::make('filament.privacy.cookies-preview'),
                ])->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }

    protected function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(Form::make([EmbeddedSchema::make('form')]))
            ->id('form')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('cookies_admin.save'))
                        ->action(fn () => $this->persist()),
                ])->key('form-actions'),
            ]);
    }

    /** The visible warning: no PUBLISHED policy page is picked (the draft value, so it follows the select). */
    public function policyWarning(): bool
    {
        return $this->publishedPageId($this->data['page'] ?? null) === null;
    }

    /**
     * The visible warning: the banner is ON (as saved) and no menu a guest sees (the header, the drawer, the footer) holds the item «Настройки за бисквитки», so the fixed fallback strip is used.
     * A menu that exists but is not attached to the header, the drawer or the footer does not count (the scan is of what the layout prepares).
     */
    public function fallbackWarning(): bool
    {
        return app(CookieSettings::class)->current()['enabled'] === true && ! app(\App\Storefront\Appearance\StorefrontAppearance::class)->cookieMenuLinkShown();
    }

    /** The preview frame's url (the draft only), and the layer the page's own control chose. */
    public function refreshPreview(): void
    {
        $this->previewSrc = CookiePreviewController::previewUrl([
            'position' => $this->data['position'] ?? 'center',
            'view' => $this->data['view'] ?? 'summary',
            'color' => $this->data['color'] ?? CookieSettings::DEFAULT_COLOR,
            'page' => $this->data['page'] ?? null,
            'texts' => is_array($this->data['texts'] ?? null) ? $this->data['texts'] : [],
            'layer' => ($this->data['preview_layer'] ?? 'summary') === 'details' ? 'details' : 'summary',
        ]);
    }

    private function publishedPageId(mixed $id): ?int
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        return DB::table('pages')->where('id', (int) $id)->where('status', 'published')->exists() ? (int) $id : null;
    }

    /** PROTECTED: only the save action calls it. */
    protected function persist(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        try {
            app(CookieSettings::class)->save($state);
        } catch (ValidationException $e) {
            $this->failFields($e->errors());
        }

        // the SAME refresh as a «Дизайн» publish: the stylesheet (colour), the payload (texts) and one purge of every page
        app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));

        $this->mount();
        Notification::make()->title(__('cookies_admin.saved'))->success()->send();
    }
}
