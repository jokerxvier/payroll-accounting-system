import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 42" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M9 4h22v18H15v16H9V4zm8 6v6h8v-6h-8z"
            />
            <path d="M4 25h28v3H4v-3zm0 6h28v3H4v-3z" />
        </svg>
    );
}
