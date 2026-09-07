/**
 * wazeBR - Landing Page JavaScript
 * Complete interactive functionality with animations and responsive features
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all components
    initNavbar();
    initMobileMenu();
    initStatsAnimation();
    initSolutionsTabs();
    initFAQ();
    initPricingToggle();
    initSmoothScroll();
    initScrollAnimations();
    initHeroParticles();
    initCounterAnimation();
});

/**
 * Navbar scroll effect
 */
function initNavbar() {
    const navbar = document.getElementById('navbar');
    if (!navbar) return;
    
    let lastScroll = 0;
    
    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 100) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
        
        lastScroll = currentScroll;
    });
}

/**
 * Mobile menu toggle
 */
function initMobileMenu() {
    const menuBtn = document.getElementById('mobile-menu-btn');
    const navMenu = document.getElementById('nav-menu');
    
    if (!menuBtn || !navMenu) return;
    
    menuBtn.addEventListener('click', () => {
        navMenu.classList.toggle('active');
        menuBtn.classList.toggle('active');
    });
    
    // Close menu on link click
    navMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            navMenu.classList.remove('active');
            menuBtn.classList.remove('active');
        });
    });
}

/**
 * Animate stats on scroll
 */
function initStatsAnimation() {
    const statsSection = document.querySelector('.stats-section');
    if (!statsSection) return;
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateStats();
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    observer.observe(statsSection);
}

function animateStats() {
    const statNumbers = document.querySelectorAll('.stat-number');
    
    statNumbers.forEach(stat => {
        const finalValue = parseInt(stat.getAttribute('data-value'));
        if (!finalValue) return;
        
        let currentValue = 0;
        const increment = finalValue / 50;
        const duration = 2000;
        const stepTime = duration / 50;
        
        const timer = setInterval(() => {
            currentValue += increment;
            if (currentValue >= finalValue) {
                stat.textContent = formatNumber(finalValue);
                clearInterval(timer);
            } else {
                stat.textContent = formatNumber(Math.floor(currentValue));
            }
        }, stepTime);
    });
}

/**
 * Format numbers with K/M suffix
 */
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(0) + 'M';
    } else if (num >= 1000) {
        return (num / 1000).toFixed(0) + 'K';
    }
    return num.toString();
}

/**
 * Solutions tabs
 */
function initSolutionsTabs() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.solution-panel');
    
    if (!tabBtns.length || !panels.length) return;
    
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.getAttribute('data-tab');
            
            // Remove active from all
            tabBtns.forEach(b => b.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));
            
            // Add active to clicked
            btn.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        });
    });
}

/**
 * FAQ accordion
 */
function initFAQ() {
    const faqItems = document.querySelectorAll('.faq-item');
    
    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        
        question.addEventListener('click', () => {
            const isActive = item.classList.contains('active');
            
            // Close all
            faqItems.forEach(i => i.classList.remove('active'));
            
            // Toggle current
            if (!isActive) {
                item.classList.add('active');
            }
        });
    });
}

/**
 * Pricing toggle (monthly/annual)
 */
function initPricingToggle() {
    const pricingSwitch = document.getElementById('pricing-switch');
    if (!pricingSwitch) return;
    
    const prices = document.querySelectorAll('.pricing-price .amount');
    const periods = document.querySelectorAll('.pricing-price .period');
    const monthlyPrices = [197, 497, 997];
    const annualPrices = [158, 398, 798]; // 20% discount
    
    pricingSwitch.addEventListener('change', () => {
        const isAnnual = pricingSwitch.checked;
        const pricesArray = isAnnual ? annualPrices : monthlyPrices;
        
        prices.forEach((price, index) => {
            animatePriceChange(price, pricesArray[index]);
        });
        
        // Update period text
        periods.forEach(period => {
            period.textContent = isAnnual ? '/m\u00eas (anual)' : '/m\u00eas';
        });
    });
}

/**
 * Animate price change
 */
function animatePriceChange(element, newValue) {
    const currentValue = parseInt(element.textContent);
    if (!currentValue) return;
    
    const increment = (newValue - currentValue) / 20;
    let count = 0;
    
    const timer = setInterval(() => {
        count++;
        const displayValue = Math.round(currentValue + (increment * count));
        element.textContent = displayValue;
        
        if (count >= 20) {
            element.textContent = newValue;
            clearInterval(timer);
        }
    }, 50);
}

/**
 * Smooth scroll for anchor links
 */
function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                e.preventDefault();
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

/**
 * Scroll animations for elements
 */
function initScrollAnimations() {
    const animatedElements = document.querySelectorAll(
        '.feature-card, .pricing-card, .testimonial-card, .step-card, .endpoint-card'
    );
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                // Stagger animation for multiple elements
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, index * 100);
                
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
}

/**
 * Hero particles animation
 */
function initHeroParticles() {
    const particlesContainer = document.getElementById('particles');
    if (!particlesContainer) return;
    
    // Create floating particles
    for (let i = 0; i < 20; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        particle.style.cssText = `
            position: absolute;
            width: ${Math.random() * 4 + 2}px;
            height: ${Math.random() * 4 + 2}px;
            background: rgba(59, 130, 246, ${Math.random() * 0.5 + 0.3});
            border-radius: 50%;
            top: ${Math.random() * 100}%;
            left: ${Math.random() * 100}%;
            animation: float ${Math.random() * 10 + 10}s infinite ease-in-out;
            animation-delay: ${Math.random() * 5}s;
        `;
        particlesContainer.appendChild(particle);
    }
    
    // Add CSS for float animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes float {
            0%, 100% {
                transform: translate(0, 0);
                opacity: 0.3;
            }
            25% {
                transform: translate(10px, -20px);
                opacity: 0.8;
            }
            50% {
                transform: translate(-10px, 20px);
                opacity: 0.5;
            }
            75% {
                transform: translate(10px, 20px);
                opacity: 0.7;
            }
        }
    `;
    document.head.appendChild(style);
}

/**
 * Counter animation for hero stats
 */
function initCounterAnimation() {
    const counters = document.querySelectorAll('.stat-value');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    counters.forEach(counter => {
        observer.observe(counter);
    });
}

function animateCounter(element) {
    const text = element.textContent;
    const hasPlus = text.includes('+');
    const number = parseInt(text.replace(/[^0-9]/g, ''));
    
    if (!number) return;
    
    let current = 0;
    const increment = number / 30;
    const duration = 1500;
    const stepTime = duration / 30;
    
    const timer = setInterval(() => {
        current += increment;
        if (current >= number) {
            element.textContent = text;
            clearInterval(timer);
        } else {
            const display = Math.floor(current);
            element.textContent = hasPlus ? display + '+' : display.toString();
        }
    }, stepTime);
}

/**
 * Typing effect for hero title
 */
function initTypingEffect() {
    const heroTitle = document.querySelector('.hero-title');
    if (!heroTitle) return;
    
    const text = heroTitle.textContent;
    heroTitle.textContent = '';
    heroTitle.style.borderRight = '3px solid var(--primary-light)';
    
    let i = 0;
    const timer = setInterval(() => {
        if (i < text.length) {
            heroTitle.textContent += text.charAt(i);
            i++;
        } else {
            clearInterval(timer);
            heroTitle.style.borderRight = 'none';
        }
    }, 50);
}

/**
 * Parallax effect for hero section
 */
function initParallax() {
    const hero = document.querySelector('.hero');
    if (!hero) return;
    
    window.addEventListener('scroll', () => {
        const scrolled = window.pageYOffset;
        const heroContent = hero.querySelector('.hero-content');
        
        if (heroContent && scrolled < 800) {
            heroContent.style.transform = `translateY(${scrolled * 0.3}px)`;
            heroContent.style.opacity = 1 - (scrolled / 800);
        }
    });
}

/**
 * Lazy load images
 */
function initLazyLoad() {
    const images = document.querySelectorAll('img[data-src]');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        });
    }, { threshold: 0.1 });
    
    images.forEach(img => observer.observe(img));
}

/**
 * Active nav link on scroll
 */
function initActiveNavLink() {
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.nav-links a');
    
    window.addEventListener('scroll', () => {
        let current = '';
        
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.clientHeight;
            
            if (pageYOffset >= sectionTop - 200) {
                current = section.getAttribute('id');
            }
        });
        
        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${current}`) {
                link.classList.add('active');
            }
        });
    });
}

/**
 * Form validation (if needed)
 */
function initFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', (e) => {
            const inputs = form.querySelectorAll('input[required]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('error');
                } else {
                    input.classList.remove('error');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
            }
        });
    });
}

/**
 * Utility: Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Utility: Throttle function
 */
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Utility: Check if element is in viewport
 */
function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}

/**
 * Handle window resize events
 */
function initResizeHandler() {
    const resizeHandler = debounce(() => {
        // Close mobile menu on resize
        const navMenu = document.getElementById('nav-menu');
        const menuBtn = document.getElementById('mobile-menu-btn');
        
        if (window.innerWidth > 768) {
            if (navMenu) navMenu.classList.remove('active');
            if (menuBtn) menuBtn.classList.remove('active');
        }
        
        // Recalculate animations if needed
        initScrollAnimations();
    }, 250);
    
    window.addEventListener('resize', resizeHandler);
}

// Initialize resize handler
initResizeHandler();

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        debounce,
        throttle,
        isInViewport,
        formatNumber
    };
}

console.log('wazeBR Landing Page initialized successfully! 🚀');
