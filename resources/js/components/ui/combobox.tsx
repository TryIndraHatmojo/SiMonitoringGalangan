import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type ComboboxOption = {
    value: string;
    label: string;
    group?: string;
};

type ComboboxProps = {
    id?: string;
    label: string;
    options: readonly ComboboxOption[];
    value: string;
    onValueChange: (value: string) => void;
    name?: string;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
};

export function Combobox({
    id,
    label,
    options,
    value,
    onValueChange,
    name,
    placeholder = 'Pilih opsi',
    disabled,
    className,
}: ComboboxProps) {
    const popupId = useId();
    const listId = `${popupId}-options`;
    const valueId = `${popupId}-value`;
    const labelId = `${popupId}-label`;
    const container = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const searchInput = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeValue, setActiveValue] = useState<string>();
    const selected = options.find((option) => option.value === value);
    const searchTerms = query.trim().toLocaleLowerCase().split(/\s+/);
    const matches = options.filter(({ label, group }) => {
        const text = `${label} ${group ?? ''}`.toLocaleLowerCase();

        return searchTerms.every((term) => text.includes(term));
    });
    const activeIndex = matches.findIndex((option) => option.value === activeValue);
    const activeId = activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined;

    useEffect(() => {
        if (!open) return;

        searchInput.current?.focus();

        function dismissOutside(event: PointerEvent) {
            if (event.target instanceof Node && !container.current?.contains(event.target)) {
                setOpen(false);
            }
        }

        document.addEventListener('pointerdown', dismissOutside);

        return () => document.removeEventListener('pointerdown', dismissOutside);
    }, [open]);

    useEffect(() => {
        if (open && activeId) {
            document.getElementById(activeId)?.scrollIntoView({ block: 'nearest' });
        }
    }, [activeId, open]);

    function showOptions() {
        setQuery('');
        setActiveValue(selected?.value ?? options[0]?.value);
        setOpen(true);
    }

    function choose(option: ComboboxOption) {
        setOpen(false);
        trigger.current?.focus();

        if (option.value !== value) onValueChange(option.value);
    }

    function handleSearchKey(event: KeyboardEvent<HTMLInputElement>) {
        if (event.nativeEvent.isComposing) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
            trigger.current?.focus();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const option = matches[activeIndex] ?? matches[0];

            if (option) choose(option);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            const nextIndex = activeIndex < 0 && direction < 0
                ? matches.length - 1
                : Math.max(0, Math.min(activeIndex + direction, matches.length - 1));

            setActiveValue(matches[nextIndex]?.value);
        }
    }

    return (
        <div
            className={cn('relative w-full min-w-0', className)}
            onBlur={(event) => {
                if (event.relatedTarget && !event.currentTarget.contains(event.relatedTarget)) {
                    setOpen(false);
                }
            }}
            ref={container}
        >
            {name && <input type="hidden" name={name} value={value} disabled={disabled} />}
            <span id={labelId} className="sr-only">{label}</span>
            <button
                ref={trigger}
                id={id}
                type="button"
                aria-labelledby={`${labelId} ${valueId}`}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-controls={open ? popupId : undefined}
                disabled={disabled}
                className="flex min-h-9 w-full min-w-0 items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-left text-sm font-normal shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50"
                onClick={() => open ? setOpen(false) : showOptions()}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        showOptions();
                    }
                }}
            >
                <span id={valueId} className="min-w-0 wrap-anywhere whitespace-normal">
                    {selected?.label ?? placeholder}
                </span>
                <ChevronsUpDown aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
            </button>
            {open && (
                <div
                    id={popupId}
                    role="dialog"
                    aria-labelledby={labelId}
                    className="absolute inset-x-0 top-full z-50 mt-1 min-w-0 rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    <Input
                        ref={searchInput}
                        role="combobox"
                        aria-label={`Cari ${label.toLocaleLowerCase()}`}
                        aria-expanded="true"
                        aria-autocomplete="list"
                        aria-controls={listId}
                        aria-activedescendant={activeId}
                        autoComplete="off"
                        placeholder="Cari..."
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setActiveValue(undefined);
                        }}
                        onKeyDown={handleSearchKey}
                    />
                    <div id={listId} role="listbox" aria-labelledby={labelId} className="mt-1 max-h-56 overflow-y-auto overscroll-contain">
                        {matches.map((option, index) => (
                            <button
                                key={option.value}
                                id={`${listId}-${index}`}
                                type="button"
                                role="option"
                                tabIndex={-1}
                                aria-selected={option.value === value}
                                className={cn(
                                    'flex w-full min-w-0 items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground',
                                    activeIndex === index && 'bg-accent text-accent-foreground',
                                )}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => choose(option)}
                            >
                                <span className="min-w-0 wrap-anywhere whitespace-normal">
                                    {option.label}
                                    {option.group && (
                                        <span className="block text-xs text-muted-foreground">{option.group}</span>
                                    )}
                                </span>
                                {option.value === value && <Check aria-hidden="true" className="size-4 shrink-0" />}
                            </button>
                        ))}
                    </div>
                    {matches.length === 0 && (
                        <p role="status" className="px-2 py-2 text-sm wrap-anywhere text-muted-foreground">
                            Opsi tidak ditemukan.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
